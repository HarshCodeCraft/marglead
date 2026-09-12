<?php
/**
 * Marg Soft Solution - Customer KYC Management Admin Module
 * Displays all submitted customer KYC records, government verification details, and document downloads.
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
}

// Security Check: Only staff/admin with access
if (!hasAccess('clients', $_SESSION['user_role'] ?? 'Admin') && !hasAccess('leads', $_SESSION['user_role'] ?? 'Admin')) {
    header('Location: ../index.php?page=dashboard');
    exit;
}

// Process Action: Approve / Reject KYC Submission
$action_msg = '';
if (isset($_POST['action']) && isset($_POST['kyc_id'])) {
    if (!verifyCsrfToken()) {
        $action_msg = "<div class='alert alert-danger'>CSRF Token verification failed.</div>";
    } else {
        $kyc_id = intval($_POST['kyc_id']);
        $new_status = $_POST['action'] === 'approve' ? 'Verified' : 'Rejected';
        $reason = trim($_POST['rejection_reason'] ?? '');

        try {
            $stmtUpd = $pdo->prepare("UPDATE customer_kyc_details SET kyc_status = ?, rejection_reason = ? WHERE id = ?");
            $stmtUpd->execute([$new_status, $reason, $kyc_id]);

            // Fetch full KYC details
            $stmtKyc = $pdo->prepare("SELECT * FROM customer_kyc_details WHERE id = ?");
            $stmtKyc->execute([$kyc_id]);
            $kycRow = $stmtKyc->fetch(PDO::FETCH_ASSOC);

            if ($kycRow && !empty($kycRow['email'])) {
                if ($new_status === 'Verified') {
                    // 1. Activate in users table
                    $stmtUserSync = $pdo->prepare("UPDATE users SET status = 'Active', role = 'Tenant Admin' WHERE LOWER(email) = LOWER(?)");
                    $stmtUserSync->execute([$kycRow['email']]);

                    // 2. Auto-Provision in tenant_companies (SaaS CRM Clients)
                    require_once __DIR__ . '/../includes/tenant_helper.php';
                    $cleanFirm = !empty($kycRow['firm_name']) ? $kycRow['firm_name'] : $kycRow['full_name'];
                    $codeSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $cleanFirm));
                    if (empty($codeSlug)) $codeSlug = 'client_' . $kycRow['id'];

                    // Check if company code already taken by someone else
                    $stmtCheckCode = $pdo->prepare("SELECT id FROM tenant_companies WHERE company_code = ? AND LOWER(owner_email) != LOWER(?)");
                    $stmtCheckCode->execute([$codeSlug, $kycRow['email']]);
                    if ($stmtCheckCode->fetch()) {
                        $codeSlug .= '_' . $kycRow['id'];
                    }

                    provisionNewCrmClient(
                        $pdo,
                        $codeSlug,
                        $cleanFirm,
                        $kycRow['full_name'],
                        $kycRow['email'],
                        $kycRow['phone'],
                        'Silver',
                        $kycRow['password'] ?? 'client123',
                        12
                    );

                    $action_msg = "<div class='alert alert-success'>🎉 Customer KYC #{$kyc_id} verified! CRM Client provisioned and visible in <a href='index.php?page=crm_clients' style='color:#fff; text-decoration:underline; font-weight:700;'>CRM Clients Console</a>.</div>";
                } else {
                    // Reject: Suspend user and tenant company
                    $stmtUserSync = $pdo->prepare("UPDATE users SET status = 'Suspended' WHERE LOWER(email) = LOWER(?)");
                    $stmtUserSync->execute([$kycRow['email']]);

                    $stmtTCSuspend = $pdo->prepare("UPDATE tenant_companies SET status = 'Suspended' WHERE LOWER(owner_email) = LOWER(?)");
                    $stmtTCSuspend->execute([$kycRow['email']]);

                    $action_msg = "<div class='alert alert-warning'>Customer KYC #{$kyc_id} rejected and accounts suspended.</div>";
                }
            }
        } catch (PDOException $e) {
            $action_msg = "<div class='alert alert-danger'>Failed to update KYC status: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Fetch Customer KYC submissions
$kyc_records = [];
if ($pdo) {
    try {
        $stmtFetch = $pdo->query("SELECT * FROM customer_kyc_details ORDER BY created_at DESC");
        $kyc_records = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
?>

<div class="content-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
    <div>
        <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 700; color: #fff;">
            <i class="fa-solid fa-file-shield text-primary"></i> Customer KYC Directory
        </h1>
        <p style="color: var(--muted); font-size: 0.9rem;">Review submitted Customer Details, Govt Document Verifications & Downloads.</p>
    </div>
    <div>
        <a href="customer_kyc_form.php" target="_blank" class="btn btn-primary" style="background: linear-gradient(135deg, var(--primary), var(--accent)); border: none; color: #fff; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Public KYC Form
        </a>
    </div>
</div>

<?php echo $action_msg; ?>

<div class="card" style="background: rgba(18, 24, 38, 0.75); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; backdrop-filter: blur(10px);">
    <?php if (empty($kyc_records)): ?>
        <div style="text-align: center; padding: 3rem 1rem; color: var(--muted);">
            <i class="fa-solid fa-folder-open" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
            <h3>No Customer KYC Submissions Yet</h3>
            <p>No customer has submitted details via the Customer KYC Form yet.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" style="width: 100%; border-collapse: collapse; color: #fff;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border); text-align: left; font-size: 0.85rem; color: var(--muted);">
                        <th style="padding: 1rem;">ID</th>
                        <th style="padding: 1rem;">Customer & Firm</th>
                        <th style="padding: 1rem;">Contact Info</th>
                        <th style="padding: 1rem;">Reg Type</th>
                        <th style="padding: 1rem;">Verified Documents</th>
                        <th style="padding: 1rem;">KYC Status</th>
                        <th style="padding: 1rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kyc_records as $rec): ?>
                        <tr style="border-bottom: 1px solid var(--border); font-size: 0.9rem;">
                            <td style="padding: 1rem; font-weight: 600; color: var(--primary);">
                                #<?php echo htmlspecialchars($rec['id']); ?>
                                <div style="font-size: 0.75rem; color: var(--muted);"><?php echo htmlspecialchars($rec['lead_id'] ?? ''); ?></div>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="font-weight: 700; color: #fff;"><?php echo htmlspecialchars($rec['firm_name']); ?></div>
                                <div style="font-size: 0.8rem; color: var(--muted);"><?php echo htmlspecialchars($rec['full_name']); ?></div>
                            </td>
                            <td style="padding: 1rem;">
                                <div><i class="fa-solid fa-envelope text-muted"></i> <?php echo htmlspecialchars($rec['email']); ?></div>
                                <div style="font-size: 0.8rem;"><i class="fa-solid fa-phone text-muted"></i> <?php echo htmlspecialchars($rec['phone']); ?></div>
                            </td>
                            <td style="padding: 1rem;">
                                <span class="badge" style="background: <?php echo $rec['registration_type'] === 'registered' ? 'rgba(59, 130, 246, 0.15)' : 'rgba(245, 158, 11, 0.15)'; ?>; color: <?php echo $rec['registration_type'] === 'registered' ? '#3b82f6' : '#f59e0b'; ?>; padding: 0.25rem 0.5rem; border-radius: 6px;">
                                    <?php echo ucfirst(htmlspecialchars($rec['registration_type'])); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.8rem;">
                                    <?php if (!empty($rec['pan_number']) || !empty($rec['pan_doc_path'])): ?>
                                    <div>
                                        <strong>PAN:</strong> <?php echo htmlspecialchars($rec['pan_number'] ?? 'N/A'); ?>
                                        <?php if (!empty($rec['pan_doc_path'])): ?>
                                            <a href="<?php echo htmlspecialchars($rec['pan_doc_path']); ?>" target="_blank" style="color: var(--primary);"><i class="fa-solid fa-download"></i> View</a>
                                        <?php endif; ?>
                                        <?php echo !empty($rec['pan_verified']) ? '<span style="color:#10b981;">✓ Live Verified</span>' : ''; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($rec['aadhaar_number']) || !empty($rec['aadhaar_doc_path'])): ?>
                                    <div>
                                        <strong>Aadhaar:</strong> <?php echo htmlspecialchars($rec['aadhaar_number'] ?? 'N/A'); ?>
                                        <?php if (!empty($rec['aadhaar_doc_path'])): ?>
                                            <a href="<?php echo htmlspecialchars($rec['aadhaar_doc_path']); ?>" target="_blank" style="color: var(--primary);"><i class="fa-solid fa-download"></i> View</a>
                                        <?php endif; ?>
                                        <?php echo !empty($rec['aadhaar_verified']) ? '<span style="color:#10b981;">✓ Live Verified</span>' : ''; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($rec['udyam_number']) || !empty($rec['udyam_doc_path'])): ?>
                                    <div>
                                        <strong>UDYAM:</strong> <?php echo htmlspecialchars($rec['udyam_number'] ?? 'N/A'); ?>
                                        <?php if (!empty($rec['udyam_doc_path'])): ?>
                                            <a href="<?php echo htmlspecialchars($rec['udyam_doc_path']); ?>" target="_blank" style="color: var(--primary);"><i class="fa-solid fa-download"></i> View</a>
                                        <?php endif; ?>
                                        <?php echo !empty($rec['udyam_verified']) ? '<span style="color:#10b981;">✓ Live Verified</span>' : ''; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($rec['gstin_number']) || !empty($rec['gstin_doc_path'])): ?>
                                        <div>
                                            <strong>GSTIN:</strong> <?php echo htmlspecialchars($rec['gstin_number'] ?? 'N/A'); ?>
                                            <?php if (!empty($rec['gstin_doc_path'])): ?>
                                                <a href="<?php echo htmlspecialchars($rec['gstin_doc_path']); ?>" target="_blank" style="color: var(--primary);"><i class="fa-solid fa-download"></i> View</a>
                                            <?php endif; ?>
                                            <?php echo !empty($rec['gstin_verified']) ? '<span style="color:#10b981;">✓ Live Verified</span>' : ''; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="padding: 1rem;">
                                <?php
                                    $st = $rec['kyc_status'];
                                    $stColor = $st === 'Verified' ? '#10b981' : ($st === 'Rejected' ? '#ef4444' : '#f59e0b');
                                ?>
                                <span class="badge" style="background: <?php echo $stColor; ?>20; color: <?php echo $stColor; ?>; border: 1px solid <?php echo $stColor; ?>40; padding: 0.35rem 0.75rem; border-radius: 20px; font-weight: 600;">
                                    <?php echo htmlspecialchars($st); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem;">
                                <form method="POST" style="display: flex; gap: 0.4rem;">
                                    <?php echo renderCsrfInput(); ?>
                                    <input type="hidden" name="kyc_id" value="<?php echo $rec['id']; ?>">
                                    <?php if ($rec['kyc_status'] !== 'Verified'): ?>
                                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-success" style="background: #10b981; border: none; color: #fff; padding: 0.35rem 0.65rem; border-radius: 6px; cursor: pointer;">
                                            <i class="fa-solid fa-check"></i> Approve
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($rec['kyc_status'] !== 'Rejected'): ?>
                                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger" style="background: #ef4444; border: none; color: #fff; padding: 0.35rem 0.65rem; border-radius: 6px; cursor: pointer;">
                                            <i class="fa-solid fa-xmark"></i> Reject
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
