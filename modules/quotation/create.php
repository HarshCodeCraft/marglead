<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';

$error_msg = '';
$selected_lead_id = isset($_GET['lead']) ? trim($_GET['lead']) : '';

// Handle POST request to save new quotation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = trim($_POST['lead_id'] ?? '');
    $validity_days = intval($_POST['validity_days'] ?? 30);
    $proposed_by = trim($_POST['proposed_by'] ?? ($_SESSION['user_name'] ?? 'Admin'));
    $taxable_amount = floatval($_POST['taxable_amount'] ?? 0);
    $gst_amount = floatval($_POST['gst_amount'] ?? 0);
    $grand_total = floatval($_POST['grand_total'] ?? 0);
    
    // Process items array
    $items = [];
    if (isset($_POST['product']) && is_array($_POST['product'])) {
        for ($i = 0; $i < count($_POST['product']); $i++) {
            $prod_name = trim($_POST['product_name'][$i] ?? $_POST['product'][$i] ?? 'Marg ERP License');
            $items[] = [
                'product' => $prod_name,
                'qty' => intval($_POST['qty'][$i] ?? 1),
                'price' => floatval($_POST['price'][$i] ?? 0),
                'gst' => floatval($_POST['gst'][$i] ?? 18),
                'total' => floatval($_POST['row_total'][$i] ?? 0)
            ];
        }
    }
    
    if (!empty($lead_id)) {
        try {
            $quote_id = 'QT-' . rand(1000, 9999);
            $issue_date = date('Y-m-d');
            $valid_until = date('Y-m-d', strtotime("+$validity_days days"));
            $items_json = json_encode($items);
            $client_email = '';
            $client_name = '';
            $company_name = '';
            
            if ($db_connected && $pdo) {
                // Fetch Lead Customer details
                $stmtL = $pdo->prepare("SELECT email, name, company FROM leads WHERE id = ?");
                $stmtL->execute([$lead_id]);
                $leadData = $stmtL->fetch(PDO::FETCH_ASSOC);
                if ($leadData) {
                    $client_email = $leadData['email'] ?? '';
                    $client_name = $leadData['name'] ?? '';
                    $company_name = $leadData['company'] ?? '';
                }

                // Insert quotation
                $stmt = $pdo->prepare("INSERT INTO quotations (id, lead_id, issue_date, valid_until, taxable_amount, gst_amount, grand_total, status, created_by, items_json) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
                $stmt->execute([$quote_id, $lead_id, $issue_date, $valid_until, $taxable_amount, $gst_amount, $grand_total, $proposed_by, $items_json]);
                
                // Update Lead Status to quotation_sent
                $updLead = $pdo->prepare("UPDATE leads SET status = 'quotation_sent' WHERE id = ?");
                $updLead->execute([$lead_id]);
                
                // Insert timeline log
                $stmtTL = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                $stmtTL->execute([$lead_id, $proposed_by, "Generated proposal ($quote_id) for ₹" . number_format($grand_total, 2)]);
                
                // Dispatch Automated Email to Client Email ID if available
                if (!empty($client_email)) {
                    Mailer::sendQuotation($quote_id, $client_email, $client_name, $company_name, $grand_total, $items_json);
                }
            }
            
            $redirect_msg = "Quotation+" . urlencode($quote_id) . "+created+and+sent+to+client+email+ID";
            header("Location: index.php?page=quotation&msg=" . $redirect_msg);
            exit;
        } catch (PDOException $e) {
            $error_msg = "Error creating quotation: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please select a target lead customer for this proposal.";
    }
}

// Fetch live Leads list from Database
$leads = [];
if ($db_connected && $pdo) {
    try {
        $stmtLeads = $pdo->query("SELECT id, name, company, phone, email FROM leads WHERE status != 'dropped' ORDER BY name ASC");
        $leads = $stmtLeads->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $leads = [];
    }
}
if (empty($leads)) {
    $leads = [
        ['id' => 'LD-9021', 'name' => 'Amit Sharma', 'company' => 'Apex Pharma Solutions', 'email' => 'asharma@apexpharma.com'],
        ['id' => 'LD-7890', 'name' => 'Satish Verma', 'company' => 'Dr. Satish Verma Clinic', 'email' => 'drverma@clinic.org'],
        ['id' => 'LD-6512', 'name' => 'Rajesh Gupta', 'company' => 'Metro Chemicals & Co.', 'email' => 'rgupta@metrochem.org']
    ];
}
?>

<div class="quotation-create-container" style="max-width: 950px; margin: 0 auto;">
    <!-- Page Header -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;">Generate New Quotation</h2>
            <p class="text-muted text-sm">Add items, configure license quantities, match tax rates, and calculate quotes totals on-the-fly.</p>
        </div>
        <a href="index.php?page=quotation" class="btn btn-secondary text-sm flex align-center gap-2">
            <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
            <span>Cancel</span>
        </a>
    </div>

    <?php if (!empty($error_msg)): ?>
        <div class="badge mb-4" style="--badge-bg: var(--danger-light); --badge-color: var(--danger); padding: 0.75rem 1rem; width: 100%; display: flex; font-size: 0.85rem;">
            <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>

    <form action="index.php?page=quotation_create" method="POST" id="quotation-create-form">
        <input type="hidden" name="action" value="save_quotation">
        <input type="hidden" name="taxable_amount" id="input-taxable" value="375000">
        <input type="hidden" name="gst_amount" id="input-gst" value="67500">
        <input type="hidden" name="grand_total" id="input-grand" value="442500">

        <!-- Client Link Profile Card -->
        <div class="card p-6 mb-6" style="border: 1px solid var(--border-color);">
            <h3 class="text-sm font-semibold mb-4" style="color: var(--primary);">1. Client & Proposal Links</h3>
            <div class="grid" style="grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Customer Lead File <span class="text-danger">*</span></label>
                    <select name="lead_id" class="form-control text-sm" required style="width: 100%; height: 38px; padding: 0.5rem;">
                        <option value="">-- Choose Lead Customer --</option>
                        <?php foreach ($leads as $l): ?>
                            <option value="<?php echo htmlspecialchars($l['id']); ?>" <?php echo ($selected_lead_id === $l['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($l['name']); ?> (<?php echo htmlspecialchars($l['company'] ?? 'Client'); ?> - <?php echo htmlspecialchars($l['email'] ?? 'No Email'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Validity Terms</label>
                    <select name="validity_days" class="form-control text-sm" style="width: 100%; height: 38px; padding: 0.5rem;">
                        <option value="15">Valid for 15 Days</option>
                        <option value="30" selected>Valid for 30 Days</option>
                        <option value="60">Valid for 60 Days</option>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Proposed By</label>
                    <input type="text" name="proposed_by" class="form-control text-sm" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?>" readonly style="background-color: var(--border-card); height: 38px; padding: 0.5rem;">
                </div>
            </div>
        </div>

        <!-- Item Details Table Matrix Card -->
        <div class="card p-6 mb-6" style="border: 1px solid var(--border-color);">
            <h3 class="text-sm font-semibold mb-4" style="color: var(--primary);">2. Item Details & Calculations Matrix</h3>
            
            <div class="table-responsive">
                <table class="table" id="quote-items-matrix">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Product / Service Description</th>
                            <th style="width: 12%;">Qty</th>
                            <th style="width: 15%;">Unit Price (INR)</th>
                            <th style="width: 12%;">GST (%)</th>
                            <th style="width: 18%;">Row Net Total (INR)</th>
                            <th style="width: 8%; text-align: right;">Delete</th>
                        </tr>
                    </thead>
                    <tbody id="quote-items-tbody">
                        <!-- Default Row 1 -->
                        <tr class="quote-item-row">
                            <td>
                                <select class="form-control item-product text-sm" onchange="updateRowCalculations(this)" style="width: 100%; height: 36px; padding: 0.4rem;">
                                    <option value="25000" data-name="Marg ERP - Basic Billing Suite">Marg ERP - Basic Billing Suite (₹25,000)</option>
                                    <option value="75000" data-name="Marg ERP - Pro Inventory Suite" selected>Marg ERP - Pro Inventory Suite (₹75,000)</option>
                                    <option value="150000" data-name="Marg ERP - Gold Enterprise Suite">Marg ERP - Gold Enterprise Suite (₹1,50,000)</option>
                                    <option value="15000" data-name="On-site Implementation & training">On-site Implementation & training (₹15,000)</option>
                                </select>
                                <input type="hidden" name="product_name[]" class="item-product-name" value="Marg ERP - Pro Inventory Suite">
                                <input type="hidden" name="product[]" class="item-product-val" value="Marg ERP - Pro Inventory Suite">
                            </td>
                            <td>
                                <input type="number" name="qty[]" class="form-control item-qty text-sm" min="1" value="5" oninput="updateRowCalculations(this)" style="width: 100%; height: 36px; padding: 0.4rem;">
                            </td>
                            <td>
                                <input type="number" name="price[]" class="form-control item-price text-sm" min="0" value="75000" oninput="updateRowCalculations(this)" style="width: 100%; height: 36px; padding: 0.4rem;">
                            </td>
                            <td>
                                <select name="gst[]" class="form-control item-gst text-sm" onchange="updateRowCalculations(this)" style="width: 100%; height: 36px; padding: 0.4rem;">
                                    <option value="5">5%</option>
                                    <option value="12">12%</option>
                                    <option value="18" selected>18%</option>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="row_total[]" class="form-control item-row-total text-sm font-bold" value="442500.00" readonly style="background-color: var(--border-card); color: var(--text-main); height: 36px; padding: 0.4rem;">
                            </td>
                            <td style="text-align: right; vertical-align: middle;">
                                <button type="button" class="btn btn-danger text-xs btn-icon" style="padding: 0.35rem 0.5rem;" onclick="removeQuoteRow(this)">
                                    <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <button type="button" class="btn btn-secondary text-xs mt-4 flex align-center gap-1" onclick="addQuoteRow()">
                <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                <span>Add Row Element</span>
            </button>
        </div>

        <!-- Calculations Summary Card -->
        <div class="card p-6 mb-6" style="border: 1px solid var(--border-color); max-width: 450px; margin-left: auto;">
            <h3 class="text-sm font-semibold mb-4" style="color: var(--primary); text-transform: uppercase;">Financial Summary</h3>
            <div class="flex flex-col gap-3">
                <div class="flex justify-between text-sm">
                    <span class="text-muted">Taxable Subtotal (INR)</span>
                    <span class="font-semibold" id="sum-taxable">₹3,75,000.00</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-muted">GST Tax Amount (INR)</span>
                    <span class="font-semibold" id="sum-gst">₹67,500.00</span>
                </div>
                <hr style="border: none; border-top: 1px solid var(--border-color);">
                <div class="flex justify-between align-center">
                    <span class="font-bold text-sm text-main">Gross Grand Total (INR)</span>
                    <span class="font-bold text-xl text-success" id="sum-grand">₹4,42,500.00</span>
                </div>
            </div>
        </div>

        <!-- Submit row -->
        <div class="flex justify-end gap-2">
            <a href="index.php?page=quotation" class="btn btn-secondary text-sm">Cancel</a>
            <button type="submit" class="btn btn-primary text-sm flex align-center gap-2" style="padding: 0.6rem 1.75rem;">
                <i data-lucide="save" style="width: 16px; height: 16px;"></i>
                <span>Save & Email Proposal</span>
            </button>
        </div>
    </form>
</div>

<script>
    function updateRowCalculations(element) {
        const row = element.closest('tr');
        const selectProd = row.querySelector('.item-product');
        const inputQty = row.querySelector('.item-qty');
        const inputPrice = row.querySelector('.item-price');
        const selectGst = row.querySelector('.item-gst');
        const inputTotal = row.querySelector('.item-row-total');
        const hiddenName = row.querySelector('.item-product-name');
        const hiddenVal = row.querySelector('.item-product-val');
        
        if (element.classList.contains('item-product')) {
            inputPrice.value = selectProd.value;
            const selectedOpt = selectProd.options[selectProd.selectedIndex];
            const pName = selectedOpt.getAttribute('data-name') || selectedOpt.text;
            if (hiddenName) hiddenName.value = pName;
            if (hiddenVal) hiddenVal.value = pName;
        }

        const qty = parseFloat(inputQty.value) || 0;
        const price = parseFloat(inputPrice.value) || 0;
        const gstPercent = parseFloat(selectGst.value) || 0;

        const subtotal = qty * price;
        const gstVal = subtotal * (gstPercent / 100);
        const total = subtotal + gstVal;

        inputTotal.value = total.toFixed(2);

        recomputeGrandTotals();
    }

    function recomputeGrandTotals() {
        const rows = document.querySelectorAll('.quote-item-row');
        let totalTaxable = 0;
        let totalGst = 0;

        rows.forEach(row => {
            const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            const gstPercent = parseFloat(row.querySelector('.item-gst').value) || 0;

            const subtotal = qty * price;
            const gstVal = subtotal * (gstPercent / 100);

            totalTaxable += subtotal;
            totalGst += gstVal;
        });

        const grandTotal = totalTaxable + totalGst;

        document.getElementById('sum-taxable').textContent = '₹' + totalTaxable.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('sum-gst').textContent = '₹' + totalGst.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('sum-grand').textContent = '₹' + grandTotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        document.getElementById('input-taxable').value = totalTaxable.toFixed(2);
        document.getElementById('input-gst').value = totalGst.toFixed(2);
        document.getElementById('input-grand').value = grandTotal.toFixed(2);
    }

    function addQuoteRow() {
        const tbody = document.getElementById('quote-items-tbody');
        const rowHTML = `
            <tr class="quote-item-row" style="animation: fadeIn 0.2s ease-in-out;">
                <td>
                    <select class="form-control item-product text-sm" onchange="updateRowCalculations(this)" style="width: 100%; height: 36px; padding: 0.4rem;">
                        <option value="25000" data-name="Marg ERP - Basic Billing Suite">Marg ERP - Basic Billing Suite (₹25,000)</option>
                        <option value="75000" data-name="Marg ERP - Pro Inventory Suite">Marg ERP - Pro Inventory Suite (₹75,000)</option>
                        <option value="150000" data-name="Marg ERP - Gold Enterprise Suite">Marg ERP - Gold Enterprise Suite (₹1,50,000)</option>
                        <option value="15000" data-name="On-site Implementation & training" selected>On-site Implementation & training (₹15,000)</option>
                    </select>
                    <input type="hidden" name="product_name[]" class="item-product-name" value="On-site Implementation & training">
                    <input type="hidden" name="product[]" class="item-product-val" value="On-site Implementation & training">
                </td>
                <td>
                    <input type="number" name="qty[]" class="form-control item-qty text-sm" min="1" value="1" oninput="updateRowCalculations(this)" style="width: 100%; height: 36px; padding: 0.4rem;">
                </td>
                <td>
                    <input type="number" name="price[]" class="form-control item-price text-sm" min="0" value="15000" oninput="updateRowCalculations(this)" style="width: 100%; height: 36px; padding: 0.4rem;">
                </td>
                <td>
                    <select name="gst[]" class="form-control item-gst text-sm" onchange="updateRowCalculations(this)" style="width: 100%; height: 36px; padding: 0.4rem;">
                        <option value="5">5%</option>
                        <option value="12">12%</option>
                        <option value="18" selected>18%</option>
                    </select>
                </td>
                <td>
                    <input type="text" name="row_total[]" class="form-control item-row-total text-sm font-bold" value="17700.00" readonly style="background-color: var(--border-card); color: var(--text-main); height: 36px; padding: 0.4rem;">
                </td>
                <td style="text-align: right; vertical-align: middle;">
                    <button type="button" class="btn btn-danger text-xs btn-icon" style="padding: 0.35rem 0.5rem;" onclick="removeQuoteRow(this)">
                        <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                    </button>
                </td>
            </tr>
        `;
        tbody.insertAdjacentHTML('beforeend', rowHTML);
        if (typeof lucide !== 'undefined') lucide.createIcons();
        recomputeGrandTotals();
    }

    function removeQuoteRow(button) {
        const row = button.closest('tr');
        const rowsCount = document.querySelectorAll('.quote-item-row').length;
        
        if (rowsCount > 1) {
            row.remove();
            recomputeGrandTotals();
        } else {
            alert('A quotation must contain at least one item.');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        recomputeGrandTotals();
    });
</script>
