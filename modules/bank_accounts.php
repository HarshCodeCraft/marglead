<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_id = $_SESSION['user_id'] ?? 1;
$is_admin = ($role === 'Administrator' || $role === 'System Admin' || $role === 'Super Admin' || $role === 'Tenant Admin' || $role === 'Regional Manager' || $user_id == 1 || !empty($_SESSION['tenant_db']));

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
                $firm_display = !empty($acc['account_name']) ? $acc['account_name'] : 'Corporate Entity';
                $subject = "Official Bank Account & Payment Details - " . $firm_display;
                $title = "Payment Transfer Details";
                $header_title = "Corporate Payment Details";
                $subtitle = "Bank Account & UPI Details for " . htmlspecialchars($firm_display);

                $body = "<p>Dear <strong>" . htmlspecialchars($client_name) . "</strong>,</p>";
                $body .= "<p>Please find below the official bank account credentials for executing wire transfers or UPI payments for <strong>" . htmlspecialchars($firm_display) . "</strong>:</p>";

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

                $support_email = $_SESSION['user_email'] ?? 'support@friendlyaisolution.com';
                $sent = Mailer::send($client_email, $subject, Mailer::wrapHTMLTemplate($title, $header_title, $subtitle, $body, "Contact Support", "mailto:" . htmlspecialchars($support_email)));
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

<div class="bank-accounts-container" style="max-width: 1240px; margin: 0 auto; padding-bottom: 3.5rem;">
    <!-- Page Header & Action Controls -->
    <div class="bank-header-banner mb-6">
        <div class="flex justify-between align-center flex-wrap gap-4">
            <div>
                <div class="flex align-center gap-2 mb-2">
                    <span class="badge" style="background: rgba(37, 99, 235, 0.12); color: var(--primary); font-weight: 700; font-size: 0.72rem; letter-spacing: 0.06em; padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;">
                        <i data-lucide="shield-check" style="width: 13px; height: 13px;"></i> CORPORATE SETTLEMENT
                    </span>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #10b981; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.06em; padding: 4px 10px; border-radius: 6px;">
                        VERIFIED MATRIX
                    </span>
                </div>
                <h2 style="font-family: var(--font-heading, 'Outfit', sans-serif); font-size: 2rem; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em; margin: 0 0 0.4rem 0;" class="flex align-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);">
                        <i data-lucide="landmark" style="width: 24px; height: 24px;"></i>
                    </div>
                    <span>Bank & Payment QR Matrix</span>
                </h2>
                <p class="text-muted text-sm m-0" style="max-width: 680px; line-height: 1.5;">
                    Corporate banking credentials, IFSC settlement branches, UPI handles, and payment QR codes. Easily view, copy, and share payment details directly with clients.
                </p>
            </div>
            <div class="flex gap-3 align-center flex-wrap">
                <?php if ($primary_account): ?>
                    <button type="button" class="btn btn-secondary text-xs flex align-center gap-2" onclick="copyFullAccountDetails(<?php echo htmlspecialchars(json_encode($primary_account)); ?>)" style="padding: 0.65rem 1.15rem; font-weight: 600; border-radius: 10px;">
                        <i data-lucide="copy" style="width: 15px; height: 15px; color: var(--primary);"></i>
                        <span>Copy Primary Account</span>
                    </button>
                <?php endif; ?>
                <?php if ($is_admin): ?>
                    <button type="button" class="btn btn-primary text-xs flex align-center gap-2" onclick="openAddAccountModal()" style="padding: 0.65rem 1.35rem; font-weight: 700; border-radius: 10px; background: linear-gradient(135deg, #2563eb, #1d4ed8); border: none; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);">
                        <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                        <span>Add Bank Account / QR</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> mb-6" style="padding: 1rem 1.25rem; border-radius: 14px; display: flex; align-items: center; justify-content: space-between; gap: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div class="flex align-center gap-3">
                <i data-lucide="<?php echo ($message_type === 'success') ? 'check-circle-2' : 'alert-circle'; ?>" style="width: 20px; height: 20px; flex-shrink: 0;"></i>
                <span style="font-size: 0.9rem; font-weight: 600;"><?php echo htmlspecialchars($message); ?></span>
            </div>
            <button type="button" onclick="this.parentElement.remove();" style="background: none; border: none; font-size: 1.3rem; cursor: pointer; color: inherit; line-height: 1;">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Corporate Overview Fintech KPI Strip -->
    <div class="grid mb-6" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
        <!-- Card 1: Total Accounts -->
        <div class="card p-5" style="border: 1px solid var(--border-color); border-radius: 16px; background: var(--bg-card); position: relative; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
            <div style="position: absolute; top: -15px; right: -15px; width: 90px; height: 90px; background: radial-gradient(circle, rgba(37, 99, 235, 0.1) 0%, transparent 70%); border-radius: 50%;"></div>
            <div class="flex align-center gap-4">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.05)); color: var(--primary); display: flex; align-items: center; justify-content: center; border: 1px solid rgba(37, 99, 235, 0.2); flex-shrink: 0;">
                    <i data-lucide="building-2" style="width: 26px; height: 26px;"></i>
                </div>
                <div>
                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em;">Registered Accounts</span>
                    <div class="flex align-center gap-2 mt-1">
                        <span class="font-bold text-main" style="font-size: 1.6rem; font-family: var(--font-heading); line-height: 1;"><?php echo count($bank_accounts); ?></span>
                        <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #10b981; font-weight: 700; font-size: 0.68rem; padding: 2px 8px; border-radius: 6px;">Available</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 2: Primary Default Account -->
        <div class="card p-5" style="border: 1px solid var(--border-color); border-radius: 16px; background: var(--bg-card); position: relative; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
            <div style="position: absolute; top: -15px; right: -15px; width: 90px; height: 90px; background: radial-gradient(circle, rgba(16, 185, 129, 0.1) 0%, transparent 70%); border-radius: 50%;"></div>
            <div class="flex align-center gap-4">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.05)); color: #10b981; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(16, 185, 129, 0.2); flex-shrink: 0;">
                    <i data-lucide="shield-check" style="width: 26px; height: 26px;"></i>
                </div>
                <div style="min-width: 0;">
                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em;">Primary Account</span>
                    <span class="font-bold text-main block text-truncate mt-1" style="font-size: 1.05rem; line-height: 1.2;">
                        <?php echo htmlspecialchars($primary_account['bank_name'] ?? 'None Set'); ?>
                    </span>
                    <span class="text-xs text-success block font-semibold mt-1" style="display: flex; align-items: center; gap: 4px;">
                        <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981;"></span>
                        ★ Ready for Client Invoices
                    </span>
                </div>
            </div>
        </div>

        <!-- Card 3: UPI VPA Handle -->
        <div class="card p-5" style="border: 1px solid var(--border-color); border-radius: 16px; background: var(--bg-card); position: relative; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
            <div style="position: absolute; top: -15px; right: -15px; width: 90px; height: 90px; background: radial-gradient(circle, rgba(139, 92, 246, 0.1) 0%, transparent 70%); border-radius: 50%;"></div>
            <div class="flex align-center gap-4">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, rgba(139, 92, 246, 0.12), rgba(139, 92, 246, 0.05)); color: #8b5cf6; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(139, 92, 246, 0.2); flex-shrink: 0;">
                    <i data-lucide="qr-code" style="width: 26px; height: 26px;"></i>
                </div>
                <div style="min-width: 0;">
                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em;">UPI Scan & Pay</span>
                    <span class="font-bold text-main font-mono block text-truncate mt-1" style="font-size: 1.05rem; line-height: 1.2; color: #8b5cf6;">
                        <?php echo htmlspecialchars($primary_account['upi_id'] ?? 'Not Configured'); ?>
                    </span>
                    <span class="text-xs text-muted block mt-1">Instant QR Code Scan & Pay</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & View Mode Switcher Control Bar -->
    <div class="card p-4 mb-6 flex justify-between align-center flex-wrap gap-4" style="border: 1px solid var(--border-color); border-radius: 14px; background: var(--bg-card);">
        <div class="flex align-center gap-3 flex-1" style="max-width: 480px;">
            <div style="position: relative; width: 100%;">
                <i data-lucide="search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: var(--text-muted); pointer-events: none;"></i>
                <input type="text" id="bank-search-input" class="form-control text-sm" placeholder="Search by bank name, account number, IFSC, or UPI handle..." oninput="filterBankCards(this.value)" style="padding-left: 38px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); height: 42px;">
            </div>
        </div>

        <div class="flex align-center gap-3">
            <span class="text-xs text-muted font-semibold">Layout:</span>
            <div class="view-mode-toggle" style="background: var(--bg-body); padding: 4px; border-radius: 10px; border: 1px solid var(--border-color); display: inline-flex; gap: 4px;">
                <button type="button" class="btn btn-sm text-xs active flex align-center gap-2" id="btn-view-cards" onclick="switchBankView('cards')" style="border-radius: 7px; padding: 0.4rem 0.85rem; font-weight: 600;">
                    <i data-lucide="grid" style="width: 14px; height: 14px;"></i>
                    <span>Card Matrix</span>
                </button>
                <button type="button" class="btn btn-sm text-xs flex align-center gap-2" id="btn-view-table" onclick="switchBankView('table')" style="border-radius: 7px; padding: 0.4rem 0.85rem; font-weight: 600;">
                    <i data-lucide="list" style="width: 14px; height: 14px;"></i>
                    <span>Table View</span>
                </button>
            </div>
        </div>
    </div>

    <!-- VIEW 1: Cards View Grid -->
    <div id="bank-cards-view" class="bank-cards-grid">
        <?php if (empty($bank_accounts)): ?>
            <div class="card p-8 text-center" style="grid-column: 1 / -1; border: 2px dashed var(--border-color); background: var(--bg-card); border-radius: 20px; padding: 3.5rem 1.5rem;">
                <div style="width: 70px; height: 70px; border-radius: 50%; background: rgba(37, 99, 235, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                    <i data-lucide="landmark" style="width: 34px; height: 34px;"></i>
                </div>
                <h3 class="text-lg font-bold mb-2" style="color: var(--text-main); font-family: var(--font-heading);">No Corporate Bank Accounts Registered</h3>
                <p class="text-sm text-muted mb-5" style="max-width: 480px; margin-left: auto; margin-right: auto; line-height: 1.6;">
                    Add your company's official bank account details, IFSC code, and payment QR code. These will be automatically formatted for client payment receipts, invoices, and WhatsApp quotes.
                </p>
                <?php if ($is_admin): ?>
                    <button type="button" class="btn btn-primary text-xs flex align-center gap-2" onclick="openAddAccountModal()" style="margin: 0 auto; padding: 0.75rem 1.5rem; font-weight: 700; border-radius: 10px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);">
                        <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                        <span>Add Your First Bank Account / QR</span>
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <?php foreach ($bank_accounts as $acc): ?>
            <?php 
            $is_prim = ($acc['is_primary'] == 1);
            $clean_qr = !empty($acc['qr_code_image']) ? ltrim($acc['qr_code_image'], '/\\') : null;
            $qr_exists = ($clean_qr && file_exists(__DIR__ . '/../' . $clean_qr));
            
            // Custom brand accent gradient per bank name
            $bank_b = strtolower($acc['bank_name']);
            $card_gradient = "linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #3b82f6 100%)";
            $chip_circuit = "#f59e0b";
            
            if (strpos($bank_b, 'hdfc') !== false) {
                $card_gradient = "linear-gradient(135deg, #0a192f 0%, #1e3a8a 55%, #2563eb 100%)";
            } elseif (strpos($bank_b, 'icici') !== false) {
                $card_gradient = "linear-gradient(135deg, #450a0a 0%, #991b1b 55%, #ea580c 100%)";
            } elseif (strpos($bank_b, 'sbi') !== false || strpos($bank_b, 'state bank') !== false) {
                $card_gradient = "linear-gradient(135deg, #082f49 0%, #0284c7 55%, #06b6d4 100%)";
            } elseif (strpos($bank_b, 'axis') !== false) {
                $card_gradient = "linear-gradient(135deg, #4a044e 0%, #86198f 55%, #c026d3 100%)";
            } elseif (strpos($bank_b, 'kotak') !== false) {
                $card_gradient = "linear-gradient(135deg, #1e1b4b 0%, #4338ca 55%, #6366f1 100%)";
            } elseif (strpos($bank_b, 'baroda') !== false || strpos($bank_b, 'bob') !== false) {
                $card_gradient = "linear-gradient(135deg, #431407 0%, #c2410c 55%, #f97316 100%)";
            }
            ?>
            <div class="bank-card card p-0 overflow-hidden <?php echo $is_prim ? 'is-primary-card' : ''; ?>" data-search="<?php echo htmlspecialchars(strtolower($acc['bank_name'] . ' ' . $acc['account_number'] . ' ' . $acc['ifsc_code'] . ' ' . $acc['upi_id'] . ' ' . $acc['account_name'])); ?>">
                
                <!-- Metallic Virtual Debit/Corporate Card Face -->
                <div class="virtual-card-face" style="background: <?php echo $card_gradient; ?>; padding: 1.6rem 1.6rem 1.3rem 1.6rem; color: #ffffff; position: relative; overflow: hidden;">
                    <!-- Card Background Watermark Graphics -->
                    <div style="position: absolute; right: -20px; bottom: -20px; width: 180px; height: 180px; border-radius: 50%; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); pointer-events: none;"></div>
                    <div style="position: absolute; right: 40px; top: -30px; width: 120px; height: 120px; border-radius: 50%; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); pointer-events: none;"></div>

                    <!-- Top Row: Bank Name + Primary Badge + Contactless Icon -->
                    <div class="flex justify-between align-start mb-4" style="position: relative; z-index: 2;">
                        <div class="flex align-center gap-3">
                            <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); display: flex; align-items: center; justify-content: center; color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.25); box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                                <i data-lucide="landmark" style="width: 22px; height: 22px;"></i>
                            </div>
                            <div>
                                <h3 class="text-base font-bold m-0" style="color: #ffffff; line-height: 1.2; font-family: var(--font-heading); letter-spacing: -0.01em;">
                                    <?php echo htmlspecialchars($acc['bank_name']); ?>
                                </h3>
                                <span class="text-xs" style="color: rgba(255, 255, 255, 0.8); font-weight: 500;">
                                    <?php echo htmlspecialchars($acc['account_type'] ?? 'Corporate Current'); ?>
                                </span>
                            </div>
                        </div>

                        <div class="flex align-center gap-2">
                            <?php if ($is_prim): ?>
                                <span class="primary-badge-chip">
                                    <i data-lucide="star" style="width: 11px; height: 11px; fill: #fbbf24; color: #fbbf24;"></i> Primary
                                </span>
                            <?php endif; ?>
                            <!-- Contactless NFC Icon -->
                            <i data-lucide="wifi" style="width: 18px; height: 18px; color: rgba(255,255,255,0.7); transform: rotate(90deg);" title="Contactless Settlement"></i>
                        </div>
                    </div>

                    <!-- Middle Row: EMV Metallic Chip Graphic + Account Number -->
                    <div class="flex align-center justify-between mb-3 mt-4" style="position: relative; z-index: 2;">
                        <!-- EMV Chip Realistic Design -->
                        <div class="emv-chip" title="Verified Corporate Settlement Account">
                            <div class="emv-line-1"></div>
                            <div class="emv-line-2"></div>
                            <div class="emv-center"></div>
                        </div>

                        <div class="text-right">
                            <span class="text-xs uppercase" style="color: rgba(255, 255, 255, 0.7); letter-spacing: 0.1em; font-weight: 600; font-size: 0.65rem;">Account Number</span>
                            <div class="font-mono font-bold tracking-widest text-lg text-white" style="text-shadow: 0 2px 4px rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
                                <span><?php echo htmlspecialchars(implode(' ', str_split($acc['account_number'], 4))); ?></span>
                                <button type="button" class="btn-icon" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['account_number']); ?>', 'Account Number')" title="Copy Account Number" style="color: rgba(255,255,255,0.85); background: rgba(255,255,255,0.15); border-radius: 6px; padding: 4px;">
                                    <i data-lucide="copy" style="width: 13px; height: 13px;"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Bottom Row: Cardholder Name + IFSC Preview -->
                    <div class="flex justify-between align-end pt-2" style="border-top: 1px solid rgba(255,255,255,0.12); position: relative; z-index: 2;">
                        <div>
                            <span class="text-xs uppercase" style="color: rgba(255, 255, 255, 0.65); letter-spacing: 0.08em; font-weight: 600; font-size: 0.62rem;">Account Holder</span>
                            <div class="text-xs font-bold text-white uppercase text-truncate" style="max-width: 200px; letter-spacing: 0.04em;">
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-xs uppercase" style="color: rgba(255, 255, 255, 0.65); letter-spacing: 0.08em; font-weight: 600; font-size: 0.62rem;">IFSC Code</span>
                            <div class="font-mono font-bold text-xs text-white uppercase">
                                <?php echo htmlspecialchars($acc['ifsc_code']); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Information Details Body -->
                <div class="p-5 flex flex-col justify-between flex-1" style="background: var(--bg-card);">
                    <div>
                        <!-- Details Grid -->
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                            <!-- Account Number Item -->
                            <div class="detail-field-box">
                                <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em; font-size: 0.68rem; margin-bottom: 0.25rem;">Account Number</span>
                                <div class="flex justify-between align-center">
                                    <span class="font-bold text-main font-mono text-sm tracking-wide"><?php echo htmlspecialchars($acc['account_number']); ?></span>
                                    <button type="button" class="btn-icon" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['account_number']); ?>', 'Account Number')" title="Copy Account Number" style="color: var(--primary);">
                                        <i data-lucide="copy" style="width: 13px; height: 13px;"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- IFSC Code Item -->
                            <div class="detail-field-box">
                                <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em; font-size: 0.68rem; margin-bottom: 0.25rem;">IFSC Code</span>
                                <div class="flex justify-between align-center">
                                    <span class="font-bold text-main font-mono text-sm uppercase"><?php echo htmlspecialchars($acc['ifsc_code']); ?></span>
                                    <button type="button" class="btn-icon" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['ifsc_code']); ?>', 'IFSC Code')" title="Copy IFSC" style="color: var(--primary);">
                                        <i data-lucide="copy" style="width: 13px; height: 13px;"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Branch Location if provided -->
                        <?php if (!empty($acc['branch'])): ?>
                            <div class="detail-field-box mb-3 flex align-center gap-2">
                                <i data-lucide="map-pin" style="width: 15px; height: 15px; color: var(--text-muted); flex-shrink: 0;"></i>
                                <div style="min-width: 0;">
                                    <span class="text-xs text-muted block" style="font-size: 0.68rem;">Branch Settlement Location:</span>
                                    <span class="text-xs font-semibold text-main text-truncate block"><?php echo htmlspecialchars($acc['branch']); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- UPI Handle Field -->
                        <?php if (!empty($acc['upi_id'])): ?>
                            <div class="detail-field-box upi-box mb-3 flex justify-between align-center">
                                <div class="flex align-center gap-2" style="min-width: 0;">
                                    <div style="width: 26px; height: 26px; border-radius: 6px; background: rgba(16, 185, 129, 0.15); color: #10b981; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i data-lucide="at-sign" style="width: 14px; height: 14px;"></i>
                                    </div>
                                    <div style="min-width: 0;">
                                        <span class="text-xs text-muted block" style="font-size: 0.68rem;">UPI VPA Handle</span>
                                        <span class="font-bold text-success font-mono text-xs text-truncate block"><?php echo htmlspecialchars($acc['upi_id']); ?></span>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-secondary text-xs copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['upi_id']); ?>', 'UPI ID')" title="Copy UPI ID" style="padding: 3px 8px;">
                                    <i data-lucide="copy" style="width: 12px; height: 12px;"></i>
                                    <span>Copy</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- Payment QR Code Card Section -->
                        <div class="qr-box-container mb-4 p-3 text-center">
                            <div class="flex justify-between align-center mb-2 px-1">
                                <span class="text-xs font-bold uppercase" style="letter-spacing: 0.05em; color: var(--text-muted); font-size: 0.7rem;">
                                    <i data-lucide="qr-code" style="width: 12px; height: 12px; vertical-align: middle; margin-right: 4px;"></i> Payment QR Code
                                </span>
                                <span class="text-xs text-success font-semibold flex align-center gap-1">
                                    <span style="width: 6px; height: 6px; border-radius: 50%; background-color: #10b981; display: inline-block;"></span> Active
                                </span>
                            </div>

                            <?php if ($qr_exists): ?>
                                <div class="qr-img-wrapper" onclick="openQRZoomModal('<?php echo htmlspecialchars($clean_qr); ?>', '<?php echo htmlspecialchars(addslashes($acc['bank_name'])); ?>', '<?php echo htmlspecialchars(addslashes($acc['upi_id'] ?? '')); ?>')">
                                    <img src="<?php echo htmlspecialchars($clean_qr); ?>" alt="Bank QR" class="qr-thumbnail-img">
                                    <div class="qr-overlay-hint">
                                        <i data-lucide="zoom-in" style="width: 24px; height: 24px; color: #ffffff; margin-bottom: 4px;"></i>
                                        <span style="color: #ffffff; font-size: 0.72rem; font-weight: 700;">Click to Zoom QR</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="padding: 1.25rem 0.5rem; background: var(--bg-body); border-radius: 10px; border: 1px dashed var(--border-color); color: var(--text-muted);">
                                    <i data-lucide="image-off" style="width: 24px; height: 24px; margin: 0 auto 6px auto; opacity: 0.5;"></i>
                                    <span class="text-xs block font-semibold">No QR Code Uploaded</span>
                                    <?php if ($is_admin): ?>
                                        <button type="button" class="btn btn-secondary text-xs mt-2" onclick="openEditAccountModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)" style="padding: 2px 8px;">
                                            Upload QR Code
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Card Actions Footer Toolbar -->
                    <div style="border-top: 1px solid var(--border-color); padding-top: 1rem;">
                        <div class="flex justify-between align-center flex-wrap gap-2">
                            <!-- Share Buttons -->
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-secondary text-xs flex align-center gap-1" onclick="shareAccountWhatsApp(<?php echo htmlspecialchars(json_encode($acc)); ?>)" title="Share via WhatsApp" style="color: #10b981; font-weight: 600; padding: 0.45rem 0.8rem; border-radius: 8px;">
                                    <i data-lucide="message-square" style="width: 14px; height: 14px;"></i>
                                    <span>WhatsApp</span>
                                </button>
                                <button type="button" class="btn btn-secondary text-xs flex align-center gap-1" onclick="openShareEmailModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)" title="Share via Email" style="color: var(--primary); font-weight: 600; padding: 0.45rem 0.8rem; border-radius: 8px;">
                                    <i data-lucide="mail" style="width: 14px; height: 14px;"></i>
                                    <span>Email</span>
                                </button>
                                <button type="button" class="btn btn-secondary text-xs flex align-center gap-1" onclick="copyFullAccountDetails(<?php echo htmlspecialchars(json_encode($acc)); ?>)" title="Copy All Details" style="font-weight: 600; padding: 0.45rem 0.75rem; border-radius: 8px;">
                                    <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                                </button>
                            </div>

                            <!-- Admin Actions -->
                            <?php if ($is_admin): ?>
                                <div class="flex gap-2 align-center">
                                    <?php if (!$is_prim): ?>
                                        <form action="index.php?page=bank_accounts" method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="set_primary">
                                            <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                                            <button type="submit" class="btn btn-secondary text-xs" style="padding: 0.45rem 0.75rem; border-radius: 8px; font-weight: 600;" title="Set as Primary Default Account">
                                                ★ Set Primary
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-secondary text-xs flex align-center gap-1" onclick="openEditAccountModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)" style="padding: 0.45rem 0.75rem; border-radius: 8px; font-weight: 600;" title="Edit Account">
                                        <i data-lucide="edit-3" style="width: 13px; height: 13px;"></i>
                                    </button>

                                    <form action="index.php?page=bank_accounts" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete this bank account?');">
                                        <input type="hidden" name="action" value="delete_account">
                                        <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                                        <button type="submit" class="btn btn-danger text-xs btn-icon" style="padding: 0.45rem 0.65rem; border-radius: 8px;" title="Delete Account">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- VIEW 2: Dense Table View Matrix (Hidden by default) -->
    <div id="bank-table-view" class="card p-0 overflow-hidden hidden" style="border: 1px solid var(--border-color); border-radius: 16px; background: var(--bg-card); box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
        <div class="table-responsive">
            <table class="table" id="bank-data-table" style="width: 100%; margin: 0;">
                <thead>
                    <tr style="background: var(--bg-body); border-bottom: 1px solid var(--border-color);">
                        <th style="padding: 1rem 1.25rem;">Bank & Type</th>
                        <th style="padding: 1rem 1.25rem;">Account Holder</th>
                        <th style="padding: 1rem 1.25rem;">Account Number</th>
                        <th style="padding: 1rem 1.25rem;">IFSC Code</th>
                        <th style="padding: 1rem 1.25rem;">UPI Handle</th>
                        <th style="padding: 1rem 1.25rem;">Status</th>
                        <th style="padding: 1rem 1.25rem; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bank_accounts as $acc): ?>
                        <?php 
                        $clean_qr = !empty($acc['qr_code_image']) ? ltrim($acc['qr_code_image'], '/\\') : null;
                        $qr_exists = ($clean_qr && file_exists(__DIR__ . '/../' . $clean_qr));
                        ?>
                        <tr data-search="<?php echo htmlspecialchars(strtolower($acc['bank_name'] . ' ' . $acc['account_number'] . ' ' . $acc['ifsc_code'] . ' ' . $acc['upi_id'] . ' ' . $acc['account_name'])); ?>" style="border-bottom: 1px solid var(--border-color);">
                            <td style="vertical-align: middle; padding: 1rem 1.25rem;">
                                <div class="flex align-center gap-3">
                                    <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(37, 99, 235, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i data-lucide="landmark" style="width: 18px; height: 18px;"></i>
                                    </div>
                                    <div>
                                        <div class="flex align-center gap-2">
                                            <span class="font-bold text-sm text-main"><?php echo htmlspecialchars($acc['bank_name']); ?></span>
                                            <?php if ($acc['is_primary'] == 1): ?>
                                                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; font-weight: 700; font-size: 0.65rem; padding: 2px 6px; border-radius: 4px;">★ Primary</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-xs text-muted"><?php echo htmlspecialchars($acc['account_type'] ?? 'Current Account'); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="font-semibold text-sm" style="vertical-align: middle; padding: 1rem 1.25rem;">
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </td>
                            <td class="font-mono font-bold text-sm" style="vertical-align: middle; padding: 1rem 1.25rem; color: var(--primary);">
                                <div class="flex align-center gap-2">
                                    <span><?php echo htmlspecialchars($acc['account_number']); ?></span>
                                    <button type="button" class="btn-icon" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['account_number']); ?>', 'Account Number')" title="Copy" style="color: var(--text-muted);">
                                        <i data-lucide="copy" style="width: 13px; height: 13px;"></i>
                                    </button>
                                </div>
                            </td>
                            <td class="font-mono text-sm uppercase" style="vertical-align: middle; padding: 1rem 1.25rem;">
                                <div class="flex align-center gap-2">
                                    <span><?php echo htmlspecialchars($acc['ifsc_code']); ?></span>
                                    <button type="button" class="btn-icon" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['ifsc_code']); ?>', 'IFSC')" title="Copy" style="color: var(--text-muted);">
                                        <i data-lucide="copy" style="width: 13px; height: 13px;"></i>
                                    </button>
                                </div>
                            </td>
                            <td class="font-mono text-sm text-success" style="vertical-align: middle; padding: 1rem 1.25rem;">
                                <?php if (!empty($acc['upi_id'])): ?>
                                    <div class="flex align-center gap-2">
                                        <span><?php echo htmlspecialchars($acc['upi_id']); ?></span>
                                        <button type="button" class="btn-icon" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['upi_id']); ?>', 'UPI ID')" title="Copy" style="color: #10b981;">
                                            <i data-lucide="copy" style="width: 13px; height: 13px;"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted text-xs">None</span>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align: middle; padding: 1rem 1.25rem;">
                                <span class="badge" style="background: <?php echo ($acc['status'] === 'Active') ? 'rgba(16, 185, 129, 0.12)' : 'rgba(239, 68, 68, 0.12)'; ?>; color: <?php echo ($acc['status'] === 'Active') ? '#10b981' : '#ef4444'; ?>; font-weight: 700; font-size: 0.7rem; padding: 3px 8px; border-radius: 6px;">
                                    <?php echo htmlspecialchars($acc['status'] ?? 'Active'); ?>
                                </span>
                            </td>
                            <td style="text-align: right; vertical-align: middle; padding: 1rem 1.25rem;">
                                <div class="flex justify-end gap-1">
                                    <?php if ($qr_exists): ?>
                                        <button type="button" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.6rem; border-radius: 6px;" onclick="openQRZoomModal('<?php echo htmlspecialchars($clean_qr); ?>', '<?php echo htmlspecialchars(addslashes($acc['bank_name'])); ?>', '<?php echo htmlspecialchars(addslashes($acc['upi_id'] ?? '')); ?>')" title="View QR Code">
                                            <i data-lucide="qr-code" style="width: 14px; height: 14px; color: #8b5cf6;"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.6rem; border-radius: 6px;" onclick="shareAccountWhatsApp(<?php echo htmlspecialchars(json_encode($acc)); ?>)" title="WhatsApp">
                                        <i data-lucide="message-square" style="width: 14px; height: 14px; color: #10b981;"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.6rem; border-radius: 6px;" onclick="openShareEmailModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)" title="Email">
                                        <i data-lucide="mail" style="width: 14px; height: 14px; color: var(--primary);"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.6rem; border-radius: 6px;" onclick="copyFullAccountDetails(<?php echo htmlspecialchars(json_encode($acc)); ?>)" title="Copy All">
                                        <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                                    </button>
                                    <?php if ($is_admin): ?>
                                        <button type="button" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.6rem; border-radius: 6px;" onclick="openEditAccountModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)" title="Edit">
                                            <i data-lucide="edit-3" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <form action="index.php?page=bank_accounts" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this bank account?');">
                                            <input type="hidden" name="action" value="delete_account">
                                            <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                                            <button type="submit" class="btn btn-danger text-xs btn-icon" style="padding: 0.4rem 0.55rem; border-radius: 6px;" title="Delete">
                                                <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                            </button>
                                        </form>
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

<!-- ======================================================================== -->
<!-- MODAL 1: ADD / EDIT BANK ACCOUNT MODAL (High-End Fintech Modal) -->
<!-- ======================================================================== -->
<?php if ($is_admin): ?>
<div class="modal-overlay" id="bank-account-modal">
    <div class="modal-container" style="max-width: 640px; width: 92%; background: var(--bg-card, #ffffff); border-radius: 20px; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.3); border: 1px solid var(--border-color); overflow: hidden; display: flex; flex-direction: column;">
        <!-- Modal Header -->
        <div class="modal-header" style="padding: 1.5rem 1.75rem; border-bottom: 1px solid var(--border-color); background: var(--bg-body); display: flex; align-items: center; justify-content: space-between;">
            <div class="flex align-center gap-3">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #ffffff; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">
                    <i data-lucide="landmark" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <h3 class="modal-title m-0" id="bank-modal-title" style="font-family: var(--font-heading); font-size: 1.25rem; font-weight: 800; color: var(--text-main); letter-spacing: -0.01em;">Add New Bank Account</h3>
                    <span class="text-xs text-muted block mt-1">Official corporate banking credentials, IFSC, and QR code setup</span>
                </div>
            </div>
            <button type="button" class="btn-icon modal-close" onclick="closeModal('bank-account-modal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;">
                <i data-lucide="x" style="width: 20px; height: 20px;"></i>
            </button>
        </div>

        <!-- Modal Form -->
        <form action="index.php?page=bank_accounts" method="POST" enctype="multipart/form-data" style="margin: 0; display: flex; flex-direction: column; flex: 1; overflow: hidden;">
            <div class="modal-body p-6" style="padding: 1.75rem; overflow-y: auto; max-height: 70vh;">
                <input type="hidden" name="action" value="save_account">
                <input type="hidden" name="account_id" id="modal-account-id" value="0">

                <div class="modal-form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <!-- Account Holder Name -->
                    <div class="form-group full-span" style="grid-column: span 2; margin-bottom: 0.25rem;">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">
                            Account Holder Name <span class="text-danger">*</span>
                        </label>
                        <div style="position: relative;">
                            <input type="text" name="account_name" id="modal-account-name" class="form-control text-sm" placeholder="e.g. ABC Enterprises Pvt Ltd / Full Name" required style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%;">
                        </div>
                    </div>

                    <!-- Bank Name -->
                    <div class="form-group" style="margin-bottom: 0.25rem;">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">
                            Bank Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="bank_name" id="modal-bank-name" class="form-control text-sm" placeholder="e.g. HDFC Bank Ltd." required style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%;">
                    </div>

                    <!-- Account Type -->
                    <div class="form-group" style="margin-bottom: 0.25rem;">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">
                            Account Type
                        </label>
                        <select name="account_type" id="modal-account-type" class="form-control text-sm" style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%; height: 42px;">
                            <option value="Current Account">Current Account</option>
                            <option value="Savings Account">Savings Account</option>
                            <option value="CC/OD Account">CC / OD Account</option>
                        </select>
                    </div>

                    <!-- Account Number -->
                    <div class="form-group" style="margin-bottom: 0.25rem;">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">
                            Account Number <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="account_number" id="modal-account-number" class="form-control text-sm font-mono font-bold" placeholder="e.g. 50200045091234" required style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%;">
                    </div>

                    <!-- IFSC Code -->
                    <div class="form-group" style="margin-bottom: 0.25rem;">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">
                            IFSC Code (11 Digits) <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="ifsc_code" id="modal-ifsc-code" class="form-control text-sm font-mono font-bold uppercase" placeholder="e.g. HDFC0000123" maxlength="11" required style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%;">
                    </div>

                    <!-- Branch Location -->
                    <div class="form-group full-span" style="grid-column: span 2; margin-bottom: 0.25rem;">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">
                            Branch Settlement Location
                        </label>
                        <input type="text" name="branch" id="modal-branch" class="form-control text-sm" placeholder="e.g. Connaught Place, New Delhi / MG Road, Bangalore" style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%;">
                    </div>

                    <!-- UPI ID Handle -->
                    <div class="form-group" style="margin-bottom: 0.25rem;">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">
                            UPI ID / VPA Handle
                        </label>
                        <input type="text" name="upi_id" id="modal-upi-id" class="form-control text-sm font-mono" placeholder="e.g. businessname@okhdfcbank / pay@upi" style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%;">
                    </div>

                    <!-- Operational Status -->
                    <div class="form-group" style="margin-bottom: 0.25rem;">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">
                            Operational Status
                        </label>
                        <select name="status" id="modal-status" class="form-control text-sm" style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%; height: 42px;">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <!-- Interactive QR Code Upload Box -->
                    <div class="form-group full-span" style="grid-column: span 2; margin-bottom: 0.5rem;">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">
                            Upload Payment QR Code Image
                        </label>
                        <div class="qr-upload-dropzone" style="border: 2px dashed var(--border-color); border-radius: 14px; padding: 1.25rem; background: var(--bg-body); text-align: center; cursor: pointer; transition: all 0.2s ease;" onclick="document.getElementById('modal-qr-input').click()">
                            <input type="file" name="qr_code_image" id="modal-qr-input" style="display: none;" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewQRUpload(this)">
                            
                            <div id="modal-qr-drop-prompt">
                                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(37, 99, 235, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; margin: 0 auto 0.65rem auto;">
                                    <i data-lucide="upload-cloud" style="width: 24px; height: 24px;"></i>
                                </div>
                                <span class="text-xs font-bold text-main block">Click or drag & drop payment QR image</span>
                                <span class="text-xs text-muted block mt-1" style="font-size: 0.725rem;">Supports JPG, PNG, WEBP, GIF (Max 5MB)</span>
                            </div>

                            <div id="modal-qr-preview-wrapper" style="display: none; align-items: center; justify-content: center; gap: 1rem;">
                                <img id="modal-qr-preview" src="" style="width: 65px; height: 65px; object-fit: contain; border-radius: 10px; border: 1px solid var(--border-color); background: #ffffff; padding: 3px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                                <div class="text-left">
                                    <span class="text-xs font-bold text-success flex align-center gap-1">
                                        <i data-lucide="check-circle" style="width: 14px; height: 14px;"></i> QR Image Selected
                                    </span>
                                    <span class="text-xs text-muted block mt-1">Click anywhere in this box to replace</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Primary Corporate Account Checkbox Card -->
                    <div class="form-group full-span" style="grid-column: span 2; margin-bottom: 0;">
                        <label class="flex align-center gap-3 p-3 border-radius-sm cursor-pointer" style="background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.2); border-radius: 12px; user-select: none; transition: all 0.2s ease;">
                            <input type="checkbox" name="is_primary" id="modal-is-primary" value="1" style="width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer;">
                            <div>
                                <strong class="text-xs block" style="color: var(--text-main);">Set as Default Primary Corporate Account</strong>
                                <span class="text-xs text-muted block" style="font-size: 0.725rem;">All generated PDF invoices, proformas, and WhatsApp links will default to this account.</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer" style="padding: 1.2rem 1.75rem; border-top: 1px solid var(--border-color); background: var(--bg-body); display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeModal('bank-account-modal')" style="padding: 0.6rem 1.25rem; font-weight: 600; border-radius: 8px;">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs font-bold flex align-center gap-2" style="padding: 0.6rem 1.5rem; border-radius: 8px; background: linear-gradient(135deg, #2563eb, #1d4ed8); border: none; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">
                    <i data-lucide="save" style="width: 15px; height: 15px;"></i>
                    <span>Save Bank Details</span>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================================== -->
<!-- MODAL 2: SHARE VIA EMAIL MODAL (Fintech Modal) -->
<!-- ======================================================================== -->
<div class="modal-overlay" id="share-email-modal">
    <div class="modal-container" style="max-width: 500px; width: 92%; background: var(--bg-card, #ffffff); border-radius: 20px; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.3); border: 1px solid var(--border-color); overflow: hidden; display: flex; flex-direction: column;">
        <div class="modal-header" style="padding: 1.4rem 1.6rem; border-bottom: 1px solid var(--border-color); background: var(--bg-body); display: flex; align-items: center; justify-content: space-between;">
            <div class="flex align-center gap-3">
                <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(37, 99, 235, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="mail" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h3 class="modal-title m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 800; color: var(--text-main);">Share Bank Details via Email</h3>
                    <span class="text-xs text-muted block mt-1">Directly email verified corporate payment credentials to client</span>
                </div>
            </div>
            <button type="button" class="btn-icon" onclick="closeModal('share-email-modal')" style="background: none; border: none; cursor: pointer; color: var(--text-muted);">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>
        <form action="index.php?page=bank_accounts" method="POST" style="margin: 0;">
            <div class="modal-body p-6" style="padding: 1.5rem;">
                <input type="hidden" name="action" value="share_email">
                <input type="hidden" name="account_id" id="email-modal-account-id" value="0">

                <div class="form-group mb-4">
                    <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">Client / Business Contact Name</label>
                    <input type="text" name="client_name" class="form-control text-sm" placeholder="e.g. Rajesh Kumar (Medical Store)" required style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%;">
                </div>
                <div class="form-group mb-2">
                    <label class="form-label text-xs font-bold" style="color: var(--text-main); display: block; margin-bottom: 0.4rem;">Client Email Address <span class="text-danger">*</span></label>
                    <input type="email" name="client_email" class="form-control text-sm" placeholder="e.g. client@company.com" required style="padding: 0.65rem 0.85rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-body); width: 100%;">
                </div>
            </div>
            <div class="modal-footer" style="padding: 1.2rem 1.6rem; border-top: 1px solid var(--border-color); background: var(--bg-body); display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeModal('share-email-modal')" style="padding: 0.6rem 1.25rem; font-weight: 600; border-radius: 8px;">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs font-bold flex align-center gap-2" style="padding: 0.6rem 1.5rem; border-radius: 8px; background: linear-gradient(135deg, #2563eb, #1d4ed8); border: none; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">
                    <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                    <span>Dispatch Email</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================== -->
<!-- MODAL 3: PAYMENT QR FULL ZOOM MODAL -->
<!-- ======================================================================== -->
<div class="modal-overlay" id="qr-zoom-modal">
    <div class="modal-container" style="max-width: 440px; width: 92%; background: var(--bg-card, #ffffff); border-radius: 20px; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.3); border: 1px solid var(--border-color); overflow: hidden; display: flex; flex-direction: column; text-align: center;">
        <div class="modal-header" style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); background: var(--bg-body); display: flex; align-items: center; justify-content: space-between;">
            <div class="flex align-center gap-2">
                <i data-lucide="qr-code" style="width: 18px; height: 18px; color: #8b5cf6;"></i>
                <h3 class="modal-title m-0" id="qr-zoom-title" style="font-family: var(--font-heading); font-size: 1.1rem; font-weight: 800; color: var(--text-main);">Payment QR Code</h3>
            </div>
            <button type="button" class="btn-icon" onclick="closeModal('qr-zoom-modal')" style="background: none; border: none; cursor: pointer; color: var(--text-muted);">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>
        <div class="modal-body p-6" style="padding: 2rem 1.5rem;">
            <div style="background: #ffffff; padding: 1.25rem; border-radius: 18px; display: inline-block; box-shadow: 0 10px 25px rgba(0,0,0,0.08); border: 1px solid var(--border-color);">
                <img id="qr-zoom-img" src="" alt="Payment QR Code Zoom" style="max-width: 240px; max-height: 240px; width: 100%; height: auto; object-fit: contain; display: block; margin: 0 auto;">
            </div>
            <div class="mt-3">
                <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em; font-size: 0.7rem;">UPI VPA Handle</span>
                <span id="qr-zoom-vpa" class="font-mono font-bold text-success text-sm block mt-1"></span>
            </div>
            <p class="text-xs text-muted mt-3 font-semibold flex align-center justify-center gap-1" style="line-height: 1.4;">
                <i data-lucide="smartphone" style="width: 14px; height: 14px; color: #10b981;"></i>
                <span>Scan using Google Pay, PhonePe, Paytm, BHIM, or any UPI banking app.</span>
            </p>
        </div>
        <div class="modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid var(--border-color); background: var(--bg-body); display: flex; align-items: center; justify-content: center; gap: 0.75rem;">
            <button type="button" class="btn btn-secondary text-xs" onclick="closeModal('qr-zoom-modal')" style="padding: 0.55rem 1.25rem; font-weight: 600; border-radius: 8px;">Close</button>
            <a id="qr-download-link" href="" download="payment_qr.png" class="btn btn-primary text-xs font-bold flex align-center gap-2" style="padding: 0.55rem 1.25rem; border-radius: 8px; text-decoration: none;">
                <i data-lucide="download" style="width: 14px; height: 14px;"></i>
                <span>Download QR</span>
            </a>
        </div>
    </div>
</div>

<style>
/* High-End Modern Fintech Styles for Bank & Payment QR Module */
.bank-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 1.75rem;
}

.bank-card {
    border: 1px solid var(--border-color);
    background-color: var(--bg-card);
    border-radius: 20px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
}

.bank-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.1);
}

.bank-card.is-primary-card {
    border: 2px solid var(--primary);
    box-shadow: 0 10px 30px rgba(37, 99, 235, 0.15);
}

.primary-badge-chip {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    letter-spacing: 0.06em;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border: 1px solid rgba(255, 255, 255, 0.3);
}

/* Realistic EMV Metallic Golden Chip */
.emv-chip {
    width: 44px;
    height: 32px;
    border-radius: 6px;
    background: linear-gradient(135deg, #fce08b 0%, #eab308 50%, #ca8a04 100%);
    border: 1px solid #a16207;
    box-shadow: inset 0 0 6px rgba(0,0,0,0.35), 0 2px 5px rgba(0,0,0,0.2);
    position: relative;
    overflow: hidden;
}

.emv-chip .emv-center {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 22px;
    height: 14px;
    border: 1px solid rgba(0,0,0,0.25);
    border-radius: 3px;
    background: rgba(234, 179, 8, 0.4);
}

.emv-chip .emv-line-1 {
    position: absolute;
    top: 0;
    left: 50%;
    width: 1px;
    height: 100%;
    background: rgba(0,0,0,0.25);
}

.emv-chip .emv-line-2 {
    position: absolute;
    top: 50%;
    left: 0;
    width: 100%;
    height: 1px;
    background: rgba(0,0,0,0.25);
}

.detail-field-box {
    background-color: var(--bg-body);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    padding: 0.75rem 0.95rem;
    transition: all 0.2s ease;
}

.detail-field-box:hover {
    border-color: rgba(37, 99, 235, 0.35);
    background-color: rgba(37, 99, 235, 0.02);
}

.detail-field-box.upi-box {
    background-color: rgba(16, 185, 129, 0.06);
    border-color: rgba(16, 185, 129, 0.2);
}

.copy-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-weight: 600;
    border-radius: 6px;
}

.qr-box-container {
    background-color: var(--bg-body);
    border-radius: 14px;
    border: 1px dashed var(--border-color);
}

.qr-img-wrapper {
    position: relative;
    display: inline-block;
    cursor: pointer;
    border-radius: 14px;
    overflow: hidden;
    background: #ffffff;
    padding: 0.6rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.06);
    border: 1px solid var(--border-color);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.qr-thumbnail-img {
    max-width: 135px;
    max-height: 135px;
    width: 135px;
    height: 135px;
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
    background: rgba(15, 23, 42, 0.82);
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

.qr-img-wrapper:hover {
    transform: scale(1.03);
    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
}

.view-mode-toggle .btn.active {
    background: var(--bg-card);
    color: var(--primary);
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
}

/* Floating Toast Notification */
#bank-toast-container {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    z-index: 999999;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    pointer-events: none;
}

.bank-toast-message {
    background-color: #0f172a;
    color: #ffffff;
    padding: 0.85rem 1.4rem;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    box-shadow: 0 15px 35px rgba(0,0,0,0.3);
    display: flex;
    align-items: center;
    gap: 0.65rem;
    animation: toastIn 0.3s ease forwards;
    pointer-events: auto;
    border: 1px solid rgba(255,255,255,0.15);
}

@keyframes toastIn {
    from { opacity: 0; transform: translateY(20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

@keyframes toastOut {
    from { opacity: 1; transform: translateY(0) scale(1); }
    to { opacity: 0; transform: translateY(20px) scale(0.95); }
}

@media (max-width: 768px) {
    .bank-cards-grid {
        grid-template-columns: 1fr;
    }
    .modal-form-grid {
        grid-template-columns: 1fr !important;
    }
    .modal-form-grid .full-span {
        grid-column: span 1 !important;
    }
}
</style>

<script>
    // Toast notification trigger
    function showToastNotification(message) {
        const container = document.getElementById('bank-toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'bank-toast-message';
        toast.innerHTML = `<i data-lucide="check-circle-2" style="width:18px; height:18px; color:#10b981; flex-shrink:0;"></i> <span>${message}</span>`;
        container.appendChild(toast);
        if (typeof lucide !== 'undefined') lucide.createIcons();

        setTimeout(() => {
            toast.style.animation = 'toastOut 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3200);
    }

    // View switcher (Cards vs Table)
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

    // Live search filter
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

    // Copy to clipboard with fallback
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

    // Copy all account details formatted
    function copyFullAccountDetails(acc) {
        const headerTitle = (acc.account_name ? acc.account_name.toUpperCase() : 'OFFICIAL PAYMENT');
        const text = `*${headerTitle} - OFFICIAL PAYMENT DETAILS*\n\n` +
            `Account Holder: ${acc.account_name}\n` +
            `Bank Name: ${acc.bank_name} (${acc.account_type || 'Current Account'})\n` +
            `Account Number: ${acc.account_number}\n` +
            `IFSC Code: ${acc.ifsc_code}\n` +
            (acc.branch ? `Branch Location: ${acc.branch}\n` : '') +
            (acc.upi_id ? `UPI ID / VPA: ${acc.upi_id}\n` : '') +
            `\nKindly transfer payment and share reference/UTR screenshot for instant receipt.`;

        copyToClipboard(text, 'Full Payment Details');
    }

    // Share via WhatsApp
    function shareAccountWhatsApp(acc) {
        const headerTitle = (acc.account_name ? acc.account_name.toUpperCase() : 'OFFICIAL PAYMENT');
        const text = `*${headerTitle} - OFFICIAL PAYMENT DETAILS*\n\n` +
            `Account Holder: ${acc.account_name}\n` +
            `Bank Name: ${acc.bank_name} (${acc.account_type || 'Current Account'})\n` +
            `Account Number: ${acc.account_number}\n` +
            `IFSC Code: ${acc.ifsc_code}\n` +
            (acc.branch ? `Branch Location: ${acc.branch}\n` : '') +
            (acc.upi_id ? `UPI ID / VPA: ${acc.upi_id}\n` : '') +
            `\nKindly transfer payment and share reference/UTR screenshot for instant receipt.`;

        const waUrl = 'https://wa.me/?text=' + encodeURIComponent(text);
        window.open(waUrl, '_blank');
    }

    // Add modal trigger
    function openAddAccountModal() {
        document.getElementById('bank-modal-title').textContent = 'Add New Corporate Bank Account';
        document.getElementById('modal-account-id').value = '0';
        document.getElementById('modal-account-name').value = '';
        document.getElementById('modal-bank-name').value = '';
        document.getElementById('modal-account-number').value = '';
        document.getElementById('modal-ifsc-code').value = '';
        document.getElementById('modal-branch').value = '';
        document.getElementById('modal-upi-id').value = '';
        document.getElementById('modal-status').value = 'Active';
        document.getElementById('modal-is-primary').checked = false;
        
        document.getElementById('modal-qr-preview').src = '';
        document.getElementById('modal-qr-preview-wrapper').style.display = 'none';
        document.getElementById('modal-qr-drop-prompt').style.display = 'block';

        window.openModal('bank-account-modal');
    }

    // Edit modal trigger
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
            document.getElementById('modal-qr-preview-wrapper').style.display = 'flex';
            document.getElementById('modal-qr-drop-prompt').style.display = 'none';
        } else {
            document.getElementById('modal-qr-preview').src = '';
            document.getElementById('modal-qr-preview-wrapper').style.display = 'none';
            document.getElementById('modal-qr-drop-prompt').style.display = 'block';
        }

        window.openModal('bank-account-modal');
    }

    // Email share modal trigger
    function openShareEmailModal(acc) {
        document.getElementById('email-modal-account-id').value = acc.id;
        window.openModal('share-email-modal');
    }

    // QR zoom modal trigger
    function openQRZoomModal(imgSrc, bankName, vpa) {
        document.getElementById('qr-zoom-title').textContent = 'Payment QR - ' + bankName;
        document.getElementById('qr-zoom-img').src = imgSrc;
        document.getElementById('qr-zoom-vpa').textContent = vpa || 'Scan & Pay via UPI';
        document.getElementById('qr-download-link').href = imgSrc;
        window.openModal('qr-zoom-modal');
    }

    // QR Image Upload Preview
    function previewQRUpload(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('modal-qr-preview');
                preview.src = e.target.result;
                document.getElementById('modal-qr-preview-wrapper').style.display = 'flex';
                document.getElementById('modal-qr-drop-prompt').style.display = 'none';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Close modal on backdrop click
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    window.closeModal(this.id);
                }
            });
        });
    });
</script>
