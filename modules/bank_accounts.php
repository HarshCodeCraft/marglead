<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_id = $_SESSION['user_id'] ?? 1;
$is_admin = ($role === 'Administrator' || $role === 'System Admin' || $role === 'Regional Manager' || $user_id == 1);

$message = '';
$message_type = 'success';

// Handle Action Requests (Add/Edit, Set Primary, Delete, Share Email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    
    // Save or Edit Bank Account (Admin / Manager only)
    if ($act === 'save_account' && $is_admin) {
        $acc_id = !empty($_POST['account_id']) ? intval($_POST['account_id']) : 0;
        $account_name = trim($_POST['account_name'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $ifsc_code = strtoupper(trim($_POST['ifsc_code'] ?? ''));
        $branch = trim($_POST['branch'] ?? '');
        $account_type = trim($_POST['account_type'] ?? 'Current Account');
        $upi_id = trim($_POST['upi_id'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;

        // Fetch existing QR Code image path if editing
        $qr_code_path = null;
        if ($acc_id > 0 && $db_connected && $pdo) {
            $stmtQ = $pdo->prepare("SELECT qr_code_image FROM bank_accounts WHERE id = ?");
            $stmtQ->execute([$acc_id]);
            $qr_code_path = $stmtQ->fetchColumn();
        }

        // Process Secure File Upload for QR Code Image
        if (isset($_FILES['qr_code_image']) && $_FILES['qr_code_image']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['qr_code_image']['tmp_name'];
            $file_name = basename($_FILES['qr_code_image']['name']);
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

            // Validate image MIME type and file extension securely
            $check_img = @getimagesize($tmp_name);
            if ($check_img !== false && in_array($file_ext, $allowed_exts)) {
                $upload_dir = __DIR__ . '/../uploads/qr_codes/';
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0777, true);
                }
                $unique_qr_name = time() . '_' . rand(1000, 9999) . '.' . $file_ext;
                $target_qr_file = $upload_dir . $unique_qr_name;

                if (move_uploaded_file($tmp_name, $target_qr_file)) {
                    $qr_code_path = 'uploads/qr_codes/' . $unique_qr_name;
                }
            } else {
                $message = "Invalid image file format. Only JPG, PNG, WEBP, and GIF images are permitted.";
                $message_type = 'danger';
            }
        }

        if (empty($message) && !empty($account_name) && !empty($account_number) && !empty($ifsc_code)) {
            if ($db_connected && $pdo) {
                try {
                    // If marked as primary, reset primary status on all other accounts
                    if ($is_primary === 1) {
                        $pdo->exec("UPDATE bank_accounts SET is_primary = 0");
                    }

                    if ($acc_id > 0) {
                        $stmt = $pdo->prepare("UPDATE bank_accounts SET account_name = ?, bank_name = ?, account_number = ?, ifsc_code = ?, branch = ?, account_type = ?, upi_id = ?, qr_code_image = ?, is_primary = ?, status = ? WHERE id = ?");
                        $stmt->execute([$account_name, $bank_name, $account_number, $ifsc_code, $branch, $account_type, $upi_id, $qr_code_path, $is_primary, $status, $acc_id]);
                        $message = "Bank account credentials & QR details updated successfully.";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO bank_accounts (account_name, bank_name, account_number, ifsc_code, branch, account_type, upi_id, qr_code_image, is_primary, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$account_name, $bank_name, $account_number, $ifsc_code, $branch, $account_type, $upi_id, $qr_code_path, $is_primary, $status]);
                        $message = "New bank account & payment QR code added successfully.";
                    }
                } catch (PDOException $e) {
                    $message = "Database error saving account: " . $e->getMessage();
                    $message_type = 'danger';
                }
            }
        } elseif (empty($message)) {
            $message = "Please fill in all required fields (Account Name, Account Number, IFSC Code).";
            $message_type = 'danger';
        }
    }

    // Set Primary Account
    if ($act === 'set_primary' && $is_admin) {
        $acc_id = intval($_POST['account_id'] ?? 0);
        if ($acc_id > 0 && $db_connected && $pdo) {
            try {
                $pdo->exec("UPDATE bank_accounts SET is_primary = 0");
                $stmt = $pdo->prepare("UPDATE bank_accounts SET is_primary = 1 WHERE id = ?");
                $stmt->execute([$acc_id]);
                $message = "Default Primary payment account updated successfully.";
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }

    // Delete Account
    if ($act === 'delete_account' && $is_admin) {
        $acc_id = intval($_POST['account_id'] ?? 0);
        if ($acc_id > 0 && $db_connected && $pdo) {
            try {
                $stmt = $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?");
                $stmt->execute([$acc_id]);
                $message = "Bank account record deleted successfully.";
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }

    // Send Payment Details via Email to Client
    if ($act === 'share_email') {
        $acc_id = intval($_POST['account_id'] ?? 0);
        $client_email = trim($_POST['client_email'] ?? '');
        $client_name = trim($_POST['client_name'] ?? 'Valued Client');

        if (!empty($client_email) && $acc_id > 0 && $db_connected && $pdo) {
            $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$acc_id]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($acc) {
                $subject = "Official Bank Account & Payment Details - Marg Soft Solutions";
                $title = "Payment Transfer Details";
                $header_title = "Corporate Payment Details";
                $subtitle = "Bank Account & UPI Details for " . htmlspecialchars($acc['account_name']);

                $body = "<p>Dear <strong>" . htmlspecialchars($client_name) . "</strong>,</p>";
                $body .= "<p>Please find below the official bank account credentials for executing wire transfers or UPI payments for <strong>Marg Soft Solutions</strong>:</p>";

                $body .= "<div style='background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 20px; margin: 20px 0;'>";
                $body .= "<table border='0' cellpadding='6' cellspacing='0' width='100%' style='font-size: 14px;'>";
                $body .= "<tr><td style='color: #64748b; width: 140px;'><strong>Account Holder:</strong></td><td style='color: #0f172a; font-weight: bold;'>" . htmlspecialchars($acc['account_name']) . "</td></tr>";
                $body .= "<tr><td style='color: #64748b;'><strong>Bank Name:</strong></td><td style='color: #0f172a; font-weight: bold;'>" . htmlspecialchars($acc['bank_name']) . " (" . htmlspecialchars($acc['account_type']) . ")</td></tr>";
                $body .= "<tr><td style='color: #64748b;'><strong>Account Number:</strong></td><td style='color: #2563eb; font-weight: bold; font-size: 16px; letter-spacing: 1px;'>" . htmlspecialchars($acc['account_number']) . "</td></tr>";
                $body .= "<tr><td style='color: #64748b;'><strong>IFSC Code:</strong></td><td style='color: #0f172a; font-weight: bold;'>" . htmlspecialchars($acc['ifsc_code']) . "</td></tr>";
                if (!empty($acc['branch'])) {
                    $body .= "<tr><td style='color: #64748b;'><strong>Branch:</strong></td><td style='color: #334155;'>" . htmlspecialchars($acc['branch']) . "</td></tr>";
                }
                if (!empty($acc['upi_id'])) {
                    $body .= "<tr><td style='color: #64748b;'><strong>UPI ID / VPA:</strong></td><td style='color: #10b981; font-weight: bold; font-size: 15px;'>" . htmlspecialchars($acc['upi_id']) . "</td></tr>";
                }
                $body .= "</table>";
                $body .= "</div>";

                $body .= "<p style='color: #64748b; font-size: 13px;'>Note: Once payment is transferred, kindly share the payment reference screenshot / UTR number with your account manager for instant receipt generation.</p>";

                $sent = Mailer::send($client_email, $subject, Mailer::wrapHTMLTemplate($title, $header_title, $subtitle, $body, "Contact Support", "mailto:support@margsoft.com"));
                if ($sent) {
                    $message = "Bank account credentials sent successfully to $client_email.";
                    $message_type = 'success';
                } else {
                    $message = "Attempted dispatch to $client_email. Status logged in database.";
                    $message_type = 'warning';
                }
            }
        }
    }
}

// Fetch all bank accounts from database
$bank_accounts = [];
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM bank_accounts ORDER BY is_primary DESC, id ASC");
        $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $bank_accounts = [];
    }
}

// If database returns empty, keep bank_accounts empty
if (empty($bank_accounts)) {
    $bank_accounts = [];
}

$primary_account = null;
foreach ($bank_accounts as $ba) {
    if ($ba['is_primary'] == 1) {
        $primary_account = $ba;
        break;
    }
}
if (!$primary_account && !empty($bank_accounts)) {
    $primary_account = $bank_accounts[0];
}
?>

<!-- Toast Notification Container -->
<div id="bank-toast-container"></div>

<div class="bank-accounts-container" style="max-width: 1200px; margin: 0 auto; padding-bottom: 3rem;">
    <!-- Page Header & Action Controls -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.85rem; font-weight: 800; color: var(--text-main);" class="mb-1 flex align-center gap-2">
                <i data-lucide="qr-code" style="width: 30px; height: 30px; color: var(--primary);"></i>
                <span>Bank & QR Payment Matrix</span>
            </h2>
            <p class="text-muted text-sm m-0">
                Corporate bank account credentials, UPI handles, and payment QR codes. Easily view, copy, and share payment details directly with clients.
            </p>
        </div>
        <div class="flex gap-2 align-center flex-wrap">
            <?php if ($primary_account): ?>
                <button type="button" class="btn btn-secondary text-xs flex align-center gap-2" onclick="copyFullAccountDetails(<?php echo htmlspecialchars(json_encode($primary_account)); ?>)" style="padding: 0.65rem 1.1rem; font-weight: 600;">
                    <i data-lucide="copy" style="width: 15px; height: 15px;"></i>
                    <span>Copy Primary Account Details</span>
                </button>
            <?php endif; ?>
            <?php if ($is_admin): ?>
                <button type="button" class="btn btn-primary text-xs flex align-center gap-2" onclick="openAddAccountModal()" style="padding: 0.65rem 1.35rem; font-weight: 700; box-shadow: var(--shadow-sm);">
                    <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
                    <span>Add Bank Account / QR</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="badge mb-6" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.9rem 1.25rem; width: 100%; display: flex; font-size: 0.875rem; border-radius: 12px; border: 1px solid rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.2);">
            <i data-lucide="info" style="width: 18px; height: 18px; margin-right: 0.6rem; flex-shrink: 0;"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- Corporate Overview Hero Banner -->
    <div class="card p-6 mb-8 overflow-hidden hero-stats-card" style="border: 1px solid var(--border-color); background: linear-gradient(135deg, rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.08) 0%, rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.02) 100%); position: relative; border-radius: 16px;">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; align-items: center;">
            <div class="flex align-center gap-4">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-md); flex-shrink: 0;">
                    <i data-lucide="building" style="width: 26px; height: 26px;"></i>
                </div>
                <div>
                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em;">Total Accounts</span>
                    <span class="font-bold text-main" style="font-size: 1.5rem; font-family: var(--font-heading);"><?php echo count($bank_accounts); ?> Registered Accounts</span>
                </div>
            </div>

            <div class="flex align-center gap-4">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-md); flex-shrink: 0;">
                    <i data-lucide="shield-check" style="width: 26px; height: 26px;"></i>
                </div>
                <div>
                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em;">Primary Default Account</span>
                    <span class="font-bold text-main" style="font-size: 1.1rem; line-height: 1.2;">
                        <?php echo htmlspecialchars($primary_account['bank_name'] ?? 'Primary Account'); ?>
                    </span>
                    <span class="text-xs text-success block font-semibold mt-1">★ Ready for Client Payments</span>
                </div>
            </div>

            <div class="flex align-center gap-4">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-md); flex-shrink: 0;">
                    <i data-lucide="smartphone" style="width: 26px; height: 26px;"></i>
                </div>
                <div>
                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em;">UPI Scan & Pay</span>
                    <span class="font-bold text-main" style="font-size: 1.1rem; line-height: 1.2;">
                        <?php echo htmlspecialchars($primary_account['upi_id'] ?? 'margsoft@okicici'); ?>
                    </span>
                    <span class="text-xs text-muted block mt-1">Google Pay / PhonePe / Paytm</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & View Switcher Bar -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4 p-4 card" style="border: 1px solid var(--border-color); border-radius: 12px; background-color: var(--bg-card);">
        <div class="flex align-center gap-2 flex-1" style="max-width: 450px;">
            <i data-lucide="search" style="width: 18px; height: 18px; color: var(--text-muted);"></i>
            <input type="text" id="bank-search-input" class="form-control text-sm" placeholder="Filter by bank name, account number, IFSC, or UPI handle..." oninput="filterBankCards(this.value)" style="border: none; background: transparent; padding: 0.35rem 0.5rem; focus: none;">
        </div>

        <div class="flex align-center gap-2">
            <span class="text-xs text-muted font-semibold mr-1">View Mode:</span>
            <div class="view-mode-toggle">
                <button type="button" class="btn btn-secondary text-xs active" id="btn-view-cards" onclick="switchBankView('cards')">
                    <i data-lucide="grid" style="width: 14px; height: 14px;"></i>
                    <span>Cards View</span>
                </button>
                <button type="button" class="btn btn-secondary text-xs" id="btn-view-table" onclick="switchBankView('table')">
                    <i data-lucide="list" style="width: 14px; height: 14px;"></i>
                    <span>Table View</span>
                </button>
            </div>
        </div>
    </div>

    <!-- VIEW 1: Cards View Grid -->
    <div id="bank-cards-view" class="bank-cards-grid">
        <?php foreach ($bank_accounts as $acc): ?>
            <?php 
            $is_prim = ($acc['is_primary'] == 1);
            $clean_qr = !empty($acc['qr_code_image']) ? ltrim($acc['qr_code_image'], '/\\') : null;
            $qr_exists = ($clean_qr && file_exists(__DIR__ . '/../' . $clean_qr));
            
            // Custom brand accent gradient per bank name
            $bank_b = strtolower($acc['bank_name']);
            $accent_gradient = "linear-gradient(135deg, var(--primary), var(--accent))";
            if (strpos($bank_b, 'hdfc') !== false) {
                $accent_gradient = "linear-gradient(135deg, #1e3a8a, #2563eb)";
            } elseif (strpos($bank_b, 'icici') !== false) {
                $accent_gradient = "linear-gradient(135deg, #c2410c, #ea580c)";
            } elseif (strpos($bank_b, 'sbi') !== false || strpos($bank_b, 'state bank') !== false) {
                $accent_gradient = "linear-gradient(135deg, #0284c7, #06b6d4)";
            } elseif (strpos($bank_b, 'axis') !== false) {
                $accent_gradient = "linear-gradient(135deg, #831843, #be185d)";
            }
            ?>
            <div class="bank-card card p-0 overflow-hidden <?php echo $is_prim ? 'is-primary-card' : ''; ?>" data-search="<?php echo htmlspecialchars(strtolower($acc['bank_name'] . ' ' . $acc['account_number'] . ' ' . $acc['ifsc_code'] . ' ' . $acc['upi_id'] . ' ' . $acc['account_name'])); ?>">
                
                <!-- Metallic Credit Card Style Header Accent -->
                <div style="background: <?php echo $accent_gradient; ?>; padding: 1.5rem; color: #ffffff; position: relative;">
                    <?php if ($is_prim): ?>
                        <span class="primary-badge-chip">
                            <i data-lucide="star" style="width: 11px; height: 11px; fill: #fff;"></i> Primary Account
                        </span>
                    <?php endif; ?>

                    <!-- Bank Header Title & EMV Chip visual -->
                    <div class="flex justify-between align-start mb-4">
                        <div class="flex align-center gap-3">
                            <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,0.25); backdrop-filter: blur(10px); display: flex; align-items: center; justify-content: center; color: #fff; border: 1px solid rgba(255,255,255,0.3);">
                                <i data-lucide="building-2" style="width: 24px; height: 24px;"></i>
                            </div>
                            <div>
                                <h3 class="text-base font-bold m-0" style="color: #ffffff; line-height: 1.2; font-family: var(--font-heading);">
                                    <?php echo htmlspecialchars($acc['bank_name']); ?>
                                </h3>
                                <span class="text-xs" style="color: rgba(255,255,255,0.9); font-weight: 500;">
                                    <?php echo htmlspecialchars($acc['account_type'] ?? 'Current Account'); ?>
                                </span>
                            </div>
                        </div>

                        <!-- EMV Metallic Chip Graphic -->
                        <div style="width: 36px; height: 26px; border-radius: 5px; background: linear-gradient(135deg, #fcd34d, #f59e0b); border: 1px solid #d97706; display: flex; align-items: center; justify-content: center; box-shadow: inset 0 0 4px rgba(0,0,0,0.2);" title="Corporate Verified Account">
                            <div style="width: 20px; height: 14px; border: 1px solid rgba(0,0,0,0.2); border-radius: 2px;"></div>
                        </div>
                    </div>

                    <!-- Embossed-style Account Number display -->
                    <div class="mt-2 mb-1">
                        <span class="text-xs uppercase" style="color: rgba(255,255,255,0.75); letter-spacing: 0.1em; font-weight: 600; font-size: 0.65rem;">Account Number</span>
                        <div class="font-mono font-bold tracking-widest text-lg text-white" style="text-shadow: 0 2px 4px rgba(0,0,0,0.3);">
                            <?php 
                            $raw_num = $acc['account_number'];
                            echo htmlspecialchars(implode(' ', str_split($raw_num, 4))); 
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Account Details Body -->
                <div class="p-6 flex flex-col justify-between flex-1" style="padding: 1.75rem;">
                    <div>
                        <!-- Account Name -->
                        <div class="detail-field-box">
                            <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em; margin-bottom: 0.2rem;">Account Holder Name</span>
                            <span class="font-bold text-main" style="font-size: 0.95rem; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </span>
                        </div>

                        <!-- Account Number Field with Quick Copy -->
                        <div class="detail-field-box flex justify-between align-center">
                            <div>
                                <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em; margin-bottom: 0.2rem;">Account Number</span>
                                <span class="font-bold text-primary font-mono text-base tracking-wide" id="acc-num-<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['account_number']); ?></span>
                            </div>
                            <button type="button" class="btn btn-secondary text-xs copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['account_number']); ?>', 'Account Number')" title="Copy Account Number">
                                <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                                <span>Copy</span>
                            </button>
                        </div>

                        <!-- IFSC Code Field with Quick Copy -->
                        <div class="detail-field-box flex justify-between align-center">
                            <div>
                                <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em; margin-bottom: 0.2rem;">IFSC Code</span>
                                <span class="font-bold text-main font-mono text-sm uppercase tracking-wide"><?php echo htmlspecialchars($acc['ifsc_code']); ?></span>
                            </div>
                            <button type="button" class="btn btn-secondary text-xs copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['ifsc_code']); ?>', 'IFSC Code')" title="Copy IFSC Code">
                                <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                                <span>Copy</span>
                            </button>
                        </div>

                        <?php if (!empty($acc['branch'])): ?>
                            <div class="detail-field-box">
                                <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em; margin-bottom: 0.2rem;">Branch Location</span>
                                <span class="text-xs font-semibold text-main"><?php echo htmlspecialchars($acc['branch']); ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- UPI ID handle -->
                        <?php if (!empty($acc['upi_id'])): ?>
                            <div class="detail-field-box upi-box flex justify-between align-center">
                                <div>
                                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em; margin-bottom: 0.2rem;">UPI ID / VPA Handle</span>
                                    <span class="font-bold text-success font-mono text-sm"><?php echo htmlspecialchars($acc['upi_id']); ?></span>
                                </div>
                                <button type="button" class="btn btn-secondary text-xs copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['upi_id']); ?>', 'UPI ID')" title="Copy UPI ID">
                                    <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                                    <span>Copy</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- QR Code Scanner Box -->
                        <div class="mt-2 mb-4 text-center p-4 qr-box-container">
                            <div class="flex justify-between align-center mb-2 px-1">
                                <span class="text-xs text-muted font-bold uppercase" style="letter-spacing: 0.05em;">UPI Scan & Pay</span>
                                <span class="text-xs text-success font-semibold flex align-center gap-1">
                                    <span style="width: 6px; height: 6px; border-radius: 50%; background-color: var(--success); display: inline-block;"></span> Active
                                </span>
                            </div>

                            <?php if ($qr_exists): ?>
                                <div class="qr-img-wrapper" onclick="openQRZoomModal('<?php echo htmlspecialchars($clean_qr); ?>', '<?php echo htmlspecialchars(addslashes($acc['bank_name'])); ?>')">
                                    <img src="<?php echo htmlspecialchars($clean_qr); ?>" alt="Payment QR Code" class="qr-thumbnail-img">
                                    <div class="qr-overlay-hint">
                                        <i data-lucide="maximize-2" style="width: 22px; height: 22px; color: #fff;"></i>
                                        <span class="text-xs text-white font-bold block mt-1">Tap to Expand QR</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="qr-img-wrapper" onclick="openQRZoomModal('<?php echo $qr_url; ?>', '<?php echo htmlspecialchars(addslashes($acc['bank_name'])); ?>')">
                                    <?php 
                                    $qr_upi = !empty($acc['upi_id']) ? $acc['upi_id'] : 'margsoft@okicici';
                                    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" . urlencode("upi://pay?pa=" . $qr_upi . "&pn=" . urlencode($acc['account_name']) . "&cu=INR");
                                    ?>
                                    <img src="<?php echo $qr_url; ?>" alt="Generated UPI QR Code" class="qr-thumbnail-img">
                                    <div class="qr-overlay-hint">
                                        <i data-lucide="maximize-2" style="width: 22px; height: 22px; color: #fff;"></i>
                                        <span class="text-xs text-white font-bold block mt-1">Tap to Expand QR</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Footer Action Controls Bar -->
                    <div class="mt-6 pt-4" style="border-top: 1px solid var(--border-color);">
                        <!-- Sharing Toolbar -->
                        <div class="share-btn-group">
                            <button type="button" class="btn btn-secondary text-xs flex-1 justify-center flex align-center gap-1" onclick="shareAccountWhatsApp(<?php echo htmlspecialchars(json_encode($acc)); ?>)" style="padding: 0.5rem;" title="Share on WhatsApp">
                                <i data-lucide="message-square" style="width: 14px; height: 14px; color: #25D366;"></i>
                                <span>WhatsApp</span>
                            </button>
                            <button type="button" class="btn btn-secondary text-xs flex-1 justify-center flex align-center gap-1" onclick="openShareEmailModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)" style="padding: 0.5rem;" title="Share via Email">
                                <i data-lucide="mail" style="width: 14px; height: 14px; color: var(--primary);"></i>
                                <span>Email</span>
                            </button>
                            <button type="button" class="btn btn-secondary text-xs flex-1 justify-center flex align-center gap-1" onclick="copyFullAccountDetails(<?php echo htmlspecialchars(json_encode($acc)); ?>)" style="padding: 0.5rem;" title="Copy Full Text">
                                <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                                <span>Copy Text</span>
                            </button>
                        </div>

                        <!-- Administrative Tools -->
                        <?php if ($is_admin): ?>
                            <div class="flex justify-between align-center pt-3 mt-3" style="border-top: 1px dashed var(--border-color);">
                                <?php if (!$is_prim): ?>
                                    <form action="index.php?page=bank_accounts" method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="set_primary">
                                        <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                                        <button type="submit" class="btn btn-secondary text-xs" style="padding: 0.25rem 0.6rem;">Set Primary</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-success font-semibold flex align-center gap-1">
                                        <i data-lucide="check-circle" style="width: 12px; height: 12px;"></i> Default Primary
                                    </span>
                                <?php endif; ?>

                                <div class="flex gap-2">
                                    <button type="button" class="btn btn-secondary text-xs flex align-center gap-1" style="padding: 0.3rem 0.6rem;" onclick="openEditAccountModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)">
                                        <i data-lucide="edit-3" style="width: 12px; height: 12px;"></i> Edit
                                    </button>
                                    <form action="index.php?page=bank_accounts" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this bank account?');">
                                        <input type="hidden" name="action" value="delete_account">
                                        <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                                        <button type="submit" class="btn btn-danger text-xs btn-icon" style="padding: 0.3rem 0.5rem;" title="Delete Account">
                                            <i data-lucide="trash-2" style="width: 13px; height: 13px;"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        <?php endforeach; ?>
    </div>

    <!-- VIEW 2: Dense Table View Matrix (Hidden by default) -->
    <div id="bank-table-view" class="card p-0 overflow-hidden hidden" style="border: 1px solid var(--border-color); border-radius: 12px;">
        <div class="table-responsive">
            <table class="table" id="bank-data-table">
                <thead>
                    <tr>
                        <th>Bank & Type</th>
                        <th>Account Holder</th>
                        <th>Account Number</th>
                        <th>IFSC Code</th>
                        <th>UPI Handle</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions & Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bank_accounts as $acc): ?>
                        <tr data-search="<?php echo htmlspecialchars(strtolower($acc['bank_name'] . ' ' . $acc['account_number'] . ' ' . $acc['ifsc_code'] . ' ' . $acc['upi_id'] . ' ' . $acc['account_name'])); ?>">
                            <td style="vertical-align: middle;">
                                <div class="flex align-center gap-2">
                                    <i data-lucide="building-2" style="width: 18px; height: 18px; color: var(--primary);"></i>
                                    <div>
                                        <span class="font-bold text-sm text-main block"><?php echo htmlspecialchars($acc['bank_name']); ?></span>
                                        <span class="text-xs text-muted"><?php echo htmlspecialchars($acc['account_type']); ?></span>
                                    </div>
                                    <?php if ($acc['is_primary'] == 1): ?>
                                        <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 0.65rem;">Primary</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="font-semibold text-sm" style="vertical-align: middle;">
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </td>
                            <td class="font-mono font-bold text-sm text-primary" style="vertical-align: middle;">
                                <?php echo htmlspecialchars($acc['account_number']); ?>
                                <button type="button" class="btn-icon" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['account_number']); ?>', 'Account Number')" title="Copy">
                                    <i data-lucide="copy" style="width: 12px; height: 12px;"></i>
                                </button>
                            </td>
                            <td class="font-mono text-sm uppercase" style="vertical-align: middle;">
                                <?php echo htmlspecialchars($acc['ifsc_code']); ?>
                                <button type="button" class="btn-icon" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['ifsc_code']); ?>', 'IFSC')" title="Copy">
                                    <i data-lucide="copy" style="width: 12px; height: 12px;"></i>
                                </button>
                            </td>
                            <td class="font-mono text-sm text-success" style="vertical-align: middle;">
                                <?php echo htmlspecialchars($acc['upi_id'] ?? 'N/A'); ?>
                            </td>
                            <td style="vertical-align: middle;">
                                <span class="badge" style="--badge-bg: <?php echo ($acc['status'] === 'Active') ? 'var(--success-light)' : 'var(--danger-light)'; ?>; --badge-color: <?php echo ($acc['status'] === 'Active') ? 'var(--success)' : 'var(--danger)'; ?>; font-size: 0.7rem;">
                                    <?php echo htmlspecialchars($acc['status'] ?? 'Active'); ?>
                                </span>
                            </td>
                            <td style="text-align: right; vertical-align: middle;">
                                <div class="flex justify-end gap-1">
                                    <button type="button" class="btn btn-secondary text-xs" style="padding: 0.3rem 0.5rem;" onclick="shareAccountWhatsApp(<?php echo htmlspecialchars(json_encode($acc)); ?>)">
                                        <i data-lucide="message-square" style="width: 13px; height: 13px; color: #25D366;"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary text-xs" style="padding: 0.3rem 0.5rem;" onclick="openShareEmailModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)">
                                        <i data-lucide="mail" style="width: 13px; height: 13px; color: var(--primary);"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary text-xs" style="padding: 0.3rem 0.5rem;" onclick="copyFullAccountDetails(<?php echo htmlspecialchars(json_encode($acc)); ?>)">
                                        <i data-lucide="copy" style="width: 13px; height: 13px;"></i>
                                    </button>
                                    <?php if ($is_admin): ?>
                                        <button type="button" class="btn btn-secondary text-xs" style="padding: 0.3rem 0.5rem;" onclick="openEditAccountModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)">
                                            <i data-lucide="edit-3" style="width: 13px; height: 13px;"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ADD / EDIT BANK ACCOUNT MODAL (Admin Only) -->
<?php if ($is_admin): ?>
<div class="modal-overlay" id="bank-account-modal">
    <div class="modal-content" style="max-width: 580px; width: 92%;">
        <div class="modal-header">
            <h3 class="modal-title" id="bank-modal-title">Add New Bank Account</h3>
            <button class="modal-close" onclick="closeModal('bank-account-modal')">&times;</button>
        </div>
        <form action="index.php?page=bank_accounts" method="POST" enctype="multipart/form-data">
            <div class="modal-body p-6">
                <input type="hidden" name="action" value="save_account">
                <input type="hidden" name="account_id" id="modal-account-id" value="0">

                <div class="modal-form-grid">
                    <div class="form-group mb-3 full-span">
                        <label class="form-label text-xs font-semibold">Account Holder Name <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" id="modal-account-name" class="form-control text-sm" placeholder="e.g. Marg Soft Solutions Pvt Ltd" required style="padding: 0.5rem 0.75rem;">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-semibold">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" id="modal-bank-name" class="form-control text-sm" placeholder="e.g. HDFC Bank Ltd." required style="padding: 0.5rem 0.75rem;">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-semibold">Account Type</label>
                        <select name="account_type" id="modal-account-type" class="form-control text-sm" style="padding: 0.5rem 0.75rem; height: 38px;">
                            <option value="Current Account">Current Account</option>
                            <option value="Savings Account">Savings Account</option>
                            <option value="CC/OD Account">CC / OD Account</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-semibold">Account Number <span class="text-danger">*</span></label>
                        <input type="text" name="account_number" id="modal-account-number" class="form-control text-sm font-mono" placeholder="e.g. 50200045091234" required style="padding: 0.5rem 0.75rem;">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-semibold">IFSC Code <span class="text-danger">*</span></label>
                        <input type="text" name="ifsc_code" id="modal-ifsc-code" class="form-control text-sm font-mono uppercase" placeholder="e.g. HDFC0000123" required style="padding: 0.5rem 0.75rem;">
                    </div>

                    <div class="form-group mb-3 full-span">
                        <label class="form-label text-xs font-semibold">Branch Location</label>
                        <input type="text" name="branch" id="modal-branch" class="form-control text-sm" placeholder="e.g. Okhla Industrial Area Phase 3, New Delhi" style="padding: 0.5rem 0.75rem;">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-semibold">UPI ID / VPA Handle</label>
                        <input type="text" name="upi_id" id="modal-upi-id" class="form-control text-sm font-mono" placeholder="e.g. margsoft@okicici" style="padding: 0.5rem 0.75rem;">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-semibold">Operational Status</label>
                        <select name="status" id="modal-status" class="form-control text-sm" style="padding: 0.5rem 0.75rem; height: 38px;">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group mb-3 full-span">
                        <label class="form-label text-xs font-semibold">Upload Payment QR Code Image</label>
                        <div class="flex align-center gap-3 mt-1">
                            <input type="file" name="qr_code_image" id="modal-qr-input" class="form-control text-xs" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewQRUpload(this)">
                            <img id="modal-qr-preview" src="" style="width: 54px; height: 54px; object-fit: contain; border-radius: 8px; border: 1px solid var(--border-color); display: none; background: #fff;">
                        </div>
                        <span class="text-xs text-muted block mt-1">Upload official bank QR code image (JPG, PNG, WEBP, GIF). Max 5MB.</span>
                    </div>

                    <div class="form-group mb-0 full-span">
                        <label class="flex align-center gap-2 cursor-pointer text-xs font-semibold" style="user-select: none;">
                            <input type="checkbox" name="is_primary" id="modal-is-primary" value="1" style="width: 16px; height: 16px; accent-color: var(--primary);">
                            <span>Set as Default Primary Corporate Account</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeModal('bank-account-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs font-bold" style="padding: 0.55rem 1.25rem;">Save Bank Details</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- SHARE VIA EMAIL MODAL -->
<div class="modal-overlay" id="share-email-modal">
    <div class="modal-content" style="max-width: 480px; width: 90%;">
        <div class="modal-header">
            <h3 class="modal-title">Share Bank Details via Email</h3>
            <button class="modal-close" onclick="closeModal('share-email-modal')">&times;</button>
        </div>
        <form action="index.php?page=bank_accounts" method="POST">
            <div class="modal-body p-6">
                <input type="hidden" name="action" value="share_email">
                <input type="hidden" name="account_id" id="email-modal-account-id" value="0">

                <div class="form-group mb-4">
                    <label class="form-label text-xs font-semibold">Client Name</label>
                    <input type="text" name="client_name" class="form-control text-sm" placeholder="Client / Company Contact Person" required style="padding: 0.5rem 0.75rem;">
                </div>
                <div class="form-group mb-3">
                    <label class="form-label text-xs font-semibold">Client Email Address <span class="text-danger">*</span></label>
                    <input type="email" name="client_email" class="form-control text-sm" placeholder="client@company.com" required style="padding: 0.5rem 0.75rem;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeModal('share-email-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs font-bold" style="padding: 0.55rem 1.25rem;">Dispatch Email</button>
            </div>
        </form>
    </div>
</div>

<!-- QR CODE FULL-SIZE ZOOM MODAL -->
<div class="modal-overlay" id="qr-zoom-modal">
    <div class="modal-content" style="max-width: 420px; text-align: center; width: 90%;">
        <div class="modal-header">
            <h3 class="modal-title" id="qr-zoom-title">Scan Payment QR Code</h3>
            <button class="modal-close" onclick="closeModal('qr-zoom-modal')">&times;</button>
        </div>
        <div class="modal-body p-6">
            <div style="background: #ffffff; padding: 1rem; border-radius: 16px; display: inline-block; box-shadow: var(--shadow-md); border: 1px solid var(--border-color);">
                <img id="qr-zoom-img" src="" alt="Payment QR Code Zoom" style="max-width: 260px; max-height: 260px; width: 100%; height: auto; object-fit: contain; display: block;">
            </div>
            <p class="text-xs text-muted mt-4 font-semibold flex align-center justify-center gap-1">
                <i data-lucide="smartphone" style="width: 14px; height: 14px;"></i>
                <span>Scan using Google Pay, PhonePe, Paytm, BHIM, or any UPI app.</span>
            </p>
        </div>
    </div>
</div>

<style>
/* Extended CSS Styles for Premium Corporate Banking UI/UX */
.view-mode-toggle {
    display: inline-flex;
    background-color: var(--border-card);
    padding: 3px;
    border-radius: var(--border-radius-sm);
    border: 1px solid var(--border-color);
}

.view-mode-toggle .btn {
    border: none !important;
    background: transparent;
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    color: var(--text-muted);
}

.view-mode-toggle .btn.active {
    background-color: var(--bg-card);
    color: var(--primary);
    font-weight: 700;
    box-shadow: var(--shadow-xs);
}

.bank-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 1.75rem;
}

.bank-card {
    border: 1px solid var(--border-color);
    background-color: var(--bg-card);
    box-shadow: var(--shadow-sm);
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    border-radius: 16px;
}

.bank-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
}

.bank-card.is-primary-card {
    border: 2px solid var(--primary);
    box-shadow: 0 8px 25px rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.15);
}

.primary-badge-chip {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 0.25rem 0.65rem;
    border-radius: var(--border-radius-full);
    letter-spacing: 0.05em;
    backdrop-filter: blur(8px);
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.detail-field-box {
    background-color: var(--border-card);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    padding: 0.85rem 1rem;
    margin-bottom: 0.75rem;
    transition: border-color 0.2s ease, background-color 0.2s ease;
}

.detail-field-box:hover {
    border-color: rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.4);
    background-color: rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.02);
}

.detail-field-box.upi-box {
    background-color: rgba(16, 185, 129, 0.06);
    border-color: rgba(16, 185, 129, 0.2);
}

.copy-btn {
    padding: 0.35rem 0.7rem;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-weight: 600;
    border-radius: 6px;
}

.qr-box-container {
    background-color: var(--border-card);
    border-radius: 12px;
    border: 1px dashed var(--border-color);
}

.qr-img-wrapper {
    position: relative;
    display: inline-block;
    cursor: pointer;
    border-radius: 12px;
    overflow: hidden;
    background: #ffffff;
    padding: 0.6rem;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
}

.qr-thumbnail-img {
    max-width: 140px;
    max-height: 140px;
    width: 140px;
    height: 140px;
    object-fit: contain;
    display: block;
    transition: transform 0.25s ease;
}

.qr-overlay-hint {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.8);
    backdrop-filter: blur(2px);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.25s ease;
}

.qr-img-wrapper:hover .qr-overlay-hint {
    opacity: 1;
}

.qr-img-wrapper:hover .qr-thumbnail-img {
    transform: scale(1.05);
}

.share-btn-group {
    display: flex;
    gap: 0.5rem;
}

.modal-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.modal-form-grid .full-span {
    grid-column: span 2;
}

/* Toast Message Floating Container */
#bank-toast-container {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    pointer-events: none;
}

.bank-toast-message {
    background-color: #0f172a;
    color: #ffffff;
    padding: 0.75rem 1.25rem;
    border-radius: 10px;
    font-size: 0.825rem;
    font-weight: 600;
    box-shadow: 0 10px 25px rgba(0,0,0,0.25);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    animation: toastIn 0.3s ease forwards;
    pointer-events: auto;
    border: 1px solid rgba(255,255,255,0.1);
}

@keyframes toastIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes toastOut {
    from { opacity: 1; transform: translateY(0); }
    to { opacity: 0; transform: translateY(20px); }
}

/* Responsive Breakpoints */
@media (max-width: 768px) {
    .bank-cards-grid {
        grid-template-columns: 1fr;
    }
    .modal-form-grid {
        grid-template-columns: 1fr;
    }
    .modal-form-grid .full-span {
        grid-column: span 1;
    }
    .share-btn-group {
        flex-wrap: wrap;
    }
}
</style>

<script>
    function showToastNotification(message) {
        const container = document.getElementById('bank-toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'bank-toast-message';
        toast.innerHTML = `<i data-lucide="check-circle" style="width:16px; height:16px; color:#10b981;"></i> <span>${message}</span>`;
        container.appendChild(toast);
        if (typeof lucide !== 'undefined') lucide.createIcons();

        setTimeout(() => {
            toast.style.animation = 'toastOut 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function switchBankView(mode) {
        const cardsView = document.getElementById('bank-cards-view');
        const tableView = document.getElementById('bank-table-view');
        const btnCards = document.getElementById('btn-view-cards');
        const btnTable = document.getElementById('btn-view-table');

        if (mode === 'table') {
            cardsView.classList.add('hidden');
            tableView.classList.remove('hidden');
            btnCards.classList.remove('active');
            btnTable.classList.add('active');
        } else {
            cardsView.classList.remove('hidden');
            tableView.classList.add('hidden');
            btnCards.classList.add('active');
            btnTable.classList.remove('active');
        }
    }

    function filterBankCards(query) {
        const q = query.toLowerCase().trim();
        const cards = document.querySelectorAll('.bank-card');
        const rows = document.querySelectorAll('#bank-data-table tbody tr');

        cards.forEach(card => {
            const data = card.getAttribute('data-search') || '';
            card.style.display = data.includes(q) ? '' : 'none';
        });

        rows.forEach(row => {
            const data = row.getAttribute('data-search') || '';
            row.style.display = data.includes(q) ? '' : 'none';
        });
    }

    function copyToClipboard(text, label) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                showToastNotification(label + ' copied to clipboard!');
            }).catch(err => {
                fallbackCopyText(text, label);
            });
        } else {
            fallbackCopyText(text, label);
        }
    }

    function fallbackCopyText(text, label) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            showToastNotification(label + ' copied to clipboard!');
        } catch (err) {
            showToastNotification('Copied: ' + text);
        }
        document.body.removeChild(textArea);
    }

    function copyFullAccountDetails(acc) {
        const text = `*MARG SOFT SOLUTIONS - BANK PAYMENT DETAILS*\n\n` +
            `Account Holder: ${acc.account_name}\n` +
            `Bank Name: ${acc.bank_name} (${acc.account_type || 'Current Account'})\n` +
            `Account Number: ${acc.account_number}\n` +
            `IFSC Code: ${acc.ifsc_code}\n` +
            (acc.branch ? `Branch: ${acc.branch}\n` : '') +
            (acc.upi_id ? `UPI ID: ${acc.upi_id}\n` : '');

        copyToClipboard(text, 'Full Payment Details');
    }

    function shareAccountWhatsApp(acc) {
        const text = `*MARG SOFT SOLUTIONS - BANK PAYMENT DETAILS*\n\n` +
            `Account Holder: ${acc.account_name}\n` +
            `Bank Name: ${acc.bank_name} (${acc.account_type || 'Current Account'})\n` +
            `Account Number: ${acc.account_number}\n` +
            `IFSC Code: ${acc.ifsc_code}\n` +
            (acc.branch ? `Branch: ${acc.branch}\n` : '') +
            (acc.upi_id ? `UPI ID: ${acc.upi_id}\n` : '') +
            `\nPlease share payment reference screenshot / UTR number after transfer.`;

        const waUrl = 'https://wa.me/?text=' + encodeURIComponent(text);
        window.open(waUrl, '_blank');
    }

    function openAddAccountModal() {
        document.getElementById('bank-modal-title').textContent = 'Add New Bank Account';
        document.getElementById('modal-account-id').value = '0';
        document.getElementById('modal-account-name').value = 'Marg Soft Solutions Pvt Ltd';
        document.getElementById('modal-bank-name').value = '';
        document.getElementById('modal-account-number').value = '';
        document.getElementById('modal-ifsc-code').value = '';
        document.getElementById('modal-branch').value = '';
        document.getElementById('modal-upi-id').value = '';
        document.getElementById('modal-status').value = 'Active';
        document.getElementById('modal-is-primary').checked = false;
        document.getElementById('modal-qr-preview').style.display = 'none';

        openModal('bank-account-modal');
    }

    function openEditAccountModal(acc) {
        document.getElementById('bank-modal-title').textContent = 'Edit Bank Account Details';
        document.getElementById('modal-account-id').value = acc.id;
        document.getElementById('modal-account-name').value = acc.account_name || '';
        document.getElementById('modal-bank-name').value = acc.bank_name || '';
        document.getElementById('modal-account-number').value = acc.account_number || '';
        document.getElementById('modal-ifsc-code').value = acc.ifsc_code || '';
        document.getElementById('modal-branch').value = acc.branch || '';
        document.getElementById('modal-account-type').value = acc.account_type || 'Current Account';
        document.getElementById('modal-upi-id').value = acc.upi_id || '';
        document.getElementById('modal-status').value = acc.status || 'Active';
        document.getElementById('modal-is-primary').checked = (acc.is_primary == 1);

        if (acc.qr_code_image) {
            const previewImg = document.getElementById('modal-qr-preview');
            previewImg.src = acc.qr_code_image;
            previewImg.style.display = 'block';
        } else {
            document.getElementById('modal-qr-preview').style.display = 'none';
        }

        openModal('bank-account-modal');
    }

    function openShareEmailModal(acc) {
        document.getElementById('email-modal-account-id').value = acc.id;
        openModal('share-email-modal');
    }

    function openQRZoomModal(imgSrc, bankName) {
        document.getElementById('qr-zoom-title').textContent = 'Payment QR - ' + bankName;
        document.getElementById('qr-zoom-img').src = imgSrc;
        openModal('qr-zoom-modal');
    }

    function previewQRUpload(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('modal-qr-preview');
                preview.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
</script>
