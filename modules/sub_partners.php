<?php
/**
 * Marg CRM - Sub Partners & Channel Network Module
 * Allows full management of Sub Partners, Dealers, Dynamic Custom Fields,
 * and seamless integration with Client Directory & License issuance.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_id = $_SESSION['user_id'] ?? 1;
$is_admin = ($role === 'Administrator' || $role === 'System Admin' || $role === 'Super Admin' || $role === 'Tenant Admin' || $role === 'Regional Manager' || $user_id == 1 || !empty($_SESSION['tenant_db']));
$is_super_admin = ($role === 'Super Admin' || $role === 'Tenant Admin' || $role === 'Admin' || $user_id == 1);

if (!$is_admin && !hasAccess('sub_partners', $role) && !hasAccess('clients', $role)) {
    header("Location: index.php?page=access_denied");
    exit;
}

$message = '';
$message_type = 'success';

// Ensure tables exist
if ($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `sub_partners` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `partner_code` VARCHAR(50) NOT NULL UNIQUE,
                `partner_name` VARCHAR(150) NOT NULL,
                `contact_person` VARCHAR(100) NULL,
                `mobile` VARCHAR(50) NULL,
                `alt_mobile` VARCHAR(50) NULL,
                `email` VARCHAR(150) NULL,
                `city` VARCHAR(100) NULL,
                `state` VARCHAR(100) NULL,
                `address` TEXT NULL,
                `gstin` VARCHAR(50) NULL,
                `commission_rate` DECIMAL(5,2) DEFAULT 0.00,
                `status` VARCHAR(20) DEFAULT 'Active',
                `remarks` TEXT NULL,
                `custom_fields` LONGTEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_code` (`partner_code`),
                INDEX `idx_name` (`partner_name`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `sub_partner_custom_fields` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `field_key` VARCHAR(64) NOT NULL UNIQUE,
                `field_label` VARCHAR(100) NOT NULL,
                `field_type` VARCHAR(30) NOT NULL DEFAULT 'text',
                `options_json` TEXT NULL,
                `placeholder` VARCHAR(150) NULL,
                `is_required` TINYINT(1) DEFAULT 0,
                `display_order` INT DEFAULT 0,
                `is_active` TINYINT(1) DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {}
}

// Fetch all dynamic custom fields definitions
$custom_fields_def = [];
if ($pdo) {
    try {
        $stmtCF = $pdo->query("SELECT * FROM sub_partner_custom_fields WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
        $custom_fields_def = $stmtCF->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// --------------------------------------------------------------------------
// 1. AJAX Endpoint: Get Sub Partner Details for Edit Modal
// --------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_partner_details') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    
    $pId = intval($_GET['id'] ?? 0);
    if ($pId > 0 && $pdo) {
        $stmt = $pdo->prepare("SELECT * FROM sub_partners WHERE id = ? LIMIT 1");
        $stmt->execute([$pId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cfData = !empty($row['custom_fields']) ? json_decode($row['custom_fields'], true) : [];
            $row['custom_fields_parsed'] = is_array($cfData) ? $cfData : [];
            echo json_encode(['success' => true, 'partner' => $row]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Partner not found']);
    exit;
}

// --------------------------------------------------------------------------
// 2. CSV Export Endpoint: Download Sub Partners Directory
// --------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_sub_partners_csv') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sub_partners_export_' . date('Y-m-d_H-i') . '.csv"');
    
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    
    // Build CSV Headers: Core + Custom Fields
    $csvHeaders = [
        'ID', 'Partner Code', 'Partner / Firm Name', 'Contact Person',
        'Mobile', 'Alt Mobile', 'Email', 'City', 'State', 'Address',
        'GSTIN', 'Commission %', 'Status', 'Linked Clients Count'
    ];
    foreach ($custom_fields_def as $cf) {
        $csvHeaders[] = $cf['field_label'];
    }
    $csvHeaders[] = 'Remarks';
    $csvHeaders[] = 'Created At';
    fputcsv($out, $csvHeaders);
    
    if ($pdo) {
        // Fetch client counts
        $countsMap = [];
        try {
            $stmtC = $pdo->query("SELECT subpartner_code, COUNT(*) as c FROM client_directory WHERE subpartner_code IS NOT NULL AND TRIM(subpartner_code) != '' GROUP BY subpartner_code");
            while ($rC = $stmtC->fetch(PDO::FETCH_ASSOC)) {
                $countsMap[strtoupper(trim($rC['subpartner_code']))] = intval($rC['c']);
            }
        } catch (Exception $e) {}

        $stmtP = $pdo->query("SELECT * FROM sub_partners ORDER BY partner_name ASC");
        while ($p = $stmtP->fetch(PDO::FETCH_ASSOC)) {
            $codeUpper = strtoupper(trim($p['partner_code']));
            $clientCount = $countsMap[$codeUpper] ?? 0;
            $cfVals = !empty($p['custom_fields']) ? json_decode($p['custom_fields'], true) : [];
            if (!is_array($cfVals)) $cfVals = [];
            
            $rowCSV = [
                $p['id'],
                $p['partner_code'],
                $p['partner_name'],
                $p['contact_person'] ?? '',
                $p['mobile'] ?? '',
                $p['alt_mobile'] ?? '',
                $p['email'] ?? '',
                $p['city'] ?? '',
                $p['state'] ?? '',
                $p['address'] ?? '',
                $p['gstin'] ?? '',
                $p['commission_rate'] ?? '0.00',
                $p['status'] ?? 'Active',
                $clientCount
            ];
            
            foreach ($custom_fields_def as $cf) {
                $k = $cf['field_key'];
                // Check if directly in column or in JSON
                $val = $p[$k] ?? ($cfVals[$k] ?? '');
                $rowCSV[] = is_array($val) ? implode(', ', $val) : $val;
            }
            
            $rowCSV[] = $p['remarks'] ?? '';
            $rowCSV[] = $p['created_at'] ?? '';
            
            fputcsv($out, $rowCSV);
        }
    }
    fclose($out);
    exit;
}

// --------------------------------------------------------------------------
// 3. Action Handlers (Save Partner, Delete Partner, Add Custom Column, Delete Custom Column)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    // --- Action A: Save (Add or Update) Sub Partner ---
    if ($act === 'save_sub_partner') {
        $pId = intval($_POST['partner_id'] ?? 0);
        $partner_code = trim($_POST['partner_code'] ?? '');
        $partner_name = trim($_POST['partner_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $alt_mobile = trim($_POST['alt_mobile'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? 'Uttar Pradesh');
        $address = trim($_POST['address'] ?? '');
        $gstin = trim($_POST['gstin'] ?? '');
        $commission_rate = floatval($_POST['commission_rate'] ?? 0.00);
        $status = trim($_POST['status'] ?? 'Active');
        $remarks = trim($_POST['remarks'] ?? '');

        // Auto-generate Partner Code if left blank
        if (empty($partner_code) && !empty($partner_name)) {
            $cleanName = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $partner_name), 0, 6));
            $partner_code = 'SP-' . ($cleanName ?: 'PARTNER') . '-' . rand(100, 999);
        }

        if (empty($partner_name)) {
            $message = 'Partner / Firm Name is required.';
            $message_type = 'danger';
        } else {
            // Collect all custom fields
            $custom_data = [];
            foreach ($custom_fields_def as $cf) {
                $fKey = $cf['field_key'];
                if (isset($_POST[$fKey])) {
                    $custom_data[$fKey] = is_array($_POST[$fKey]) ? implode(', ', $_POST[$fKey]) : trim($_POST[$fKey]);
                }
            }
            $custom_json = json_encode($custom_data, JSON_UNESCAPED_UNICODE);

            if ($pdo) {
                try {
                    // Check duplicate code
                    $dupCheck = $pdo->prepare("SELECT id FROM sub_partners WHERE partner_code = ? AND id != ? LIMIT 1");
                    $dupCheck->execute([$partner_code, $pId]);
                    if ($dupCheck->fetch()) {
                        $message = "Partner Code '<strong>" . htmlspecialchars($partner_code) . "</strong>' already exists. Please choose a unique code.";
                        $message_type = 'danger';
                    } else {
                        if ($pId > 0) {
                            // Update
                            $stmtU = $pdo->prepare("
                                UPDATE sub_partners SET
                                    partner_code = ?,
                                    partner_name = ?,
                                    contact_person = ?,
                                    mobile = ?,
                                    alt_mobile = ?,
                                    email = ?,
                                    city = ?,
                                    state = ?,
                                    address = ?,
                                    gstin = ?,
                                    commission_rate = ?,
                                    status = ?,
                                    remarks = ?,
                                    custom_fields = ?
                                WHERE id = ?
                            ");
                            $stmtU->execute([
                                $partner_code, $partner_name, $contact_person, $mobile, $alt_mobile,
                                $email, $city, $state, $address, $gstin, $commission_rate,
                                $status, $remarks, $custom_json, $pId
                            ]);

                            // Also sync custom columns if they exist as native columns
                            foreach ($custom_data as $colName => $colVal) {
                                try {
                                    $colStmt = $pdo->prepare("UPDATE sub_partners SET `{$colName}` = ? WHERE id = ?");
                                    $colStmt->execute([$colVal, $pId]);
                                } catch (Exception $eCol) {}
                            }

                            $message = "Sub-Partner <strong>" . htmlspecialchars($partner_name) . "</strong> updated successfully!";
                            $message_type = 'success';
                        } else {
                            // Insert
                            $stmtI = $pdo->prepare("
                                INSERT INTO sub_partners (
                                    partner_code, partner_name, contact_person, mobile, alt_mobile,
                                    email, city, state, address, gstin, commission_rate,
                                    status, remarks, custom_fields
                                ) VALUES (
                                    ?, ?, ?, ?, ?,
                                    ?, ?, ?, ?, ?, ?,
                                    ?, ?, ?
                                )
                            ");
                            $stmtI->execute([
                                $partner_code, $partner_name, $contact_person, $mobile, $alt_mobile,
                                $email, $city, $state, $address, $gstin, $commission_rate,
                                $status, $remarks, $custom_json
                            ]);
                            $newPId = $pdo->lastInsertId();

                            // Also sync custom columns if they exist as native columns
                            foreach ($custom_data as $colName => $colVal) {
                                try {
                                    $colStmt = $pdo->prepare("UPDATE sub_partners SET `{$colName}` = ? WHERE id = ?");
                                    $colStmt->execute([$colVal, $newPId]);
                                } catch (Exception $eCol) {}
                            }

                            $message = "New Sub-Partner <strong>" . htmlspecialchars($partner_name) . "</strong> registered successfully (Code: " . htmlspecialchars($partner_code) . ")!";
                            $message_type = 'success';
                        }
                    }
                } catch (Exception $e) {
                    $message = "Database error saving Sub-Partner: " . $e->getMessage();
                    $message_type = 'danger';
                }
            }
        }
    }

    // --- Action B: Delete Sub Partner ---
    if ($act === 'delete_sub_partner' && $is_admin) {
        $pId = intval($_POST['partner_id'] ?? 0);
        if ($pId > 0 && $pdo) {
            try {
                // Get partner code first to check linked clients
                $codeStmt = $pdo->prepare("SELECT partner_code, partner_name FROM sub_partners WHERE id = ?");
                $codeStmt->execute([$pId]);
                $pRow = $codeStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($pRow) {
                    $pCode = $pRow['partner_code'];
                    $pName = $pRow['partner_name'];
                    
                    // Check if clients are linked
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM client_directory WHERE subpartner_code = ? OR subpartner_name = ?");
                    $cntStmt->execute([$pCode, $pName]);
                    $linkedCount = $cntStmt->fetchColumn();
                    
                    if ($linkedCount > 0) {
                        // Mark Inactive instead of deleting to preserve client historical records
                        $upd = $pdo->prepare("UPDATE sub_partners SET status = 'Inactive' WHERE id = ?");
                        $upd->execute([$pId]);
                        $message = "Sub-Partner <strong>" . htmlspecialchars($pName) . "</strong> has {$linkedCount} linked client(s). The partner was marked as <strong>Inactive</strong> to preserve client history.";
                        $message_type = 'warning';
                    } else {
                        $del = $pdo->prepare("DELETE FROM sub_partners WHERE id = ?");
                        $del->execute([$pId]);
                        $message = "Sub-Partner <strong>" . htmlspecialchars($pName) . "</strong> deleted successfully.";
                        $message_type = 'success';
                    }
                }
            } catch (Exception $e) {
                $message = "Error deleting Sub-Partner: " . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }

    // --- Action C: Super Admin Add Dynamic Custom Field / Column ---
    if ($act === 'add_custom_field' && $is_super_admin) {
        $field_label = trim($_POST['field_label'] ?? '');
        $field_type = trim($_POST['field_type'] ?? 'text');
        $placeholder = trim($_POST['placeholder'] ?? '');
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $options_raw = trim($_POST['options_list'] ?? '');

        if (empty($field_label)) {
            $message = "Field Title / Label is required to create a new custom column.";
            $message_type = 'danger';
        } else {
            // Generate clean SQL-safe field_key from label
            $base_key = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', str_replace(['-', ' '], '_', $field_label)));
            $base_key = trim(preg_replace('/_+/', '_', $base_key), '_');
            if (empty($base_key)) $base_key = 'field_' . time();
            
            // Check uniqueness
            $checkKey = $pdo->prepare("SELECT COUNT(*) FROM sub_partner_custom_fields WHERE field_key = ?");
            $checkKey->execute([$base_key]);
            $field_key = $base_key;
            if ($checkKey->fetchColumn() > 0) {
                $field_key = $base_key . '_' . rand(10, 99);
            }

            // Options JSON for select dropdown
            $options_json = null;
            if ($field_type === 'select' && !empty($options_raw)) {
                $opts = array_filter(array_map('trim', explode(',', $options_raw)));
                $options_json = json_encode(array_values($opts));
            }

            // Get max display order
            $maxOrder = $pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM sub_partner_custom_fields")->fetchColumn();
            $nextOrder = intval($maxOrder) + 1;

            if ($pdo) {
                try {
                    // 1. Insert into custom fields catalog
                    $stmtF = $pdo->prepare("
                        INSERT INTO sub_partner_custom_fields (
                            field_key, field_label, field_type, options_json, placeholder, is_required, display_order, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmtF->execute([$field_key, $field_label, $field_type, $options_json, $placeholder, $is_required, $nextOrder]);

                    // 2. Run ALTER TABLE to add native column to sub_partners table
                    $colSqlType = ($field_type === 'number') ? "DECIMAL(12,2) NULL" : (($field_type === 'date') ? "DATE NULL" : "VARCHAR(255) NULL");
                    try {
                        $pdo->exec("ALTER TABLE `sub_partners` ADD COLUMN `{$field_key}` {$colSqlType}");
                    } catch (Exception $eCol) {}

                    $message = "New custom column '<strong>" . htmlspecialchars($field_label) . "</strong>' added successfully! It is now active across all forms and the Sub-Partners table.";
                    $message_type = 'success';

                    // Refresh custom fields definition array
                    $stmtCF = $pdo->query("SELECT * FROM sub_partner_custom_fields WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
                    $custom_fields_def = $stmtCF->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $message = "Error adding custom column: " . $e->getMessage();
                    $message_type = 'danger';
                }
            }
        }
    }

    // --- Action D: Delete / Toggle Dynamic Custom Column ---
    if ($act === 'delete_custom_field' && $is_super_admin) {
        $fId = intval($_POST['field_id'] ?? 0);
        if ($fId > 0 && $pdo) {
            try {
                $delF = $pdo->prepare("DELETE FROM sub_partner_custom_fields WHERE id = ?");
                $delF->execute([$fId]);
                $message = "Custom column removed successfully from directory view.";
                $message_type = 'success';

                // Refresh custom fields definition array
                $stmtCF = $pdo->query("SELECT * FROM sub_partner_custom_fields WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
                $custom_fields_def = $stmtCF->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $message = "Error removing custom column: " . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}

// --------------------------------------------------------------------------
// 4. Data Queries & Filters
// --------------------------------------------------------------------------
$search_q = trim($_GET['search'] ?? '');
$status_f = trim($_GET['status'] ?? '');
$city_f = trim($_GET['city'] ?? '');

$where_clauses = ["1=1"];
$params = [];

if (!empty($search_q)) {
    $where_clauses[] = "(partner_code LIKE ? OR partner_name LIKE ? OR contact_person LIKE ? OR mobile LIKE ? OR email LIKE ? OR city LIKE ? OR state LIKE ? OR gstin LIKE ?)";
    $sLike = '%' . $search_q . '%';
    for ($i = 0; $i < 8; $i++) {
        $params[] = $sLike;
    }
}

if (!empty($status_f)) {
    $where_clauses[] = "status = ?";
    $params[] = $status_f;
}

if (!empty($city_f)) {
    $where_clauses[] = "city = ?";
    $params[] = $city_f;
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch client counts mapped to partners
$client_counts_by_code = [];
$total_linked_clients_count = 0;
if ($pdo) {
    try {
        $stmtC = $pdo->query("
            SELECT subpartner_code, subpartner_name, COUNT(*) as c 
            FROM client_directory 
            WHERE (subpartner_code IS NOT NULL AND TRIM(subpartner_code) != '')
               OR (subpartner_name IS NOT NULL AND TRIM(subpartner_name) != '')
            GROUP BY subpartner_code
        ");
        while ($rowC = $stmtC->fetch(PDO::FETCH_ASSOC)) {
            $codeK = strtoupper(trim($rowC['subpartner_code']));
            if (!empty($codeK)) {
                $client_counts_by_code[$codeK] = intval($rowC['c']);
                $total_linked_clients_count += intval($rowC['c']);
            }
        }
    } catch (Exception $e) {}
}

// Stats summary queries
$total_partners_count = 0;
$active_partners_count = 0;
$top_partner_name = 'N/A';
$top_partner_clients = 0;

if ($pdo) {
    try {
        $total_partners_count = $pdo->query("SELECT COUNT(*) FROM sub_partners")->fetchColumn();
        $active_partners_count = $pdo->query("SELECT COUNT(*) FROM sub_partners WHERE status = 'Active'")->fetchColumn();
        
        // Find top partner by client count
        $stmtTop = $pdo->query("
            SELECT subpartner_name, subpartner_code, COUNT(*) as cnt 
            FROM client_directory 
            WHERE subpartner_name IS NOT NULL AND TRIM(subpartner_name) != '' 
            GROUP BY subpartner_name 
            ORDER BY cnt DESC 
            LIMIT 1
        ");
        $topRow = $stmtTop->fetch(PDO::FETCH_ASSOC);
        if ($topRow && $topRow['cnt'] > 0) {
            $top_partner_name = $topRow['subpartner_name'];
            $top_partner_clients = intval($topRow['cnt']);
        }
    } catch (Exception $e) {}
}

// Fetch distinct cities for filter dropdown
$distinct_cities = [];
if ($pdo) {
    try {
        $stmtCities = $pdo->query("SELECT DISTINCT city FROM sub_partners WHERE city IS NOT NULL AND TRIM(city) != '' ORDER BY city ASC");
        $distinct_cities = $stmtCities->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}

// Fetch sub partners list
$partners_list = [];
if ($pdo) {
    try {
        $stmtP = $pdo->prepare("SELECT * FROM sub_partners WHERE {$where_sql} ORDER BY partner_name ASC");
        $stmtP->execute($params);
        $partners_list = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>

<div class="content-wrapper" style="padding: 1.25rem 1.5rem; max-width: 1600px; margin: 0 auto;">
    
    <!-- TOP HEADER & ACTION BAR -->
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%); display: flex; align-items: center; justify-content: center; color: #fff; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
                    <i data-lucide="handshake" style="width: 24px; height: 24px;"></i>
                </div>
                <div>
                    <h1 style="font-size: 1.5rem; font-weight: 800; margin: 0; color: var(--text-main); letter-spacing: -0.02em;">
                        Sub-Partners & Channel Network
                    </h1>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 2px 0 0 0;">
                        Manage channel partners, sub-dealers, customizable partner attributes, and client distribution.
                    </p>
                </div>
            </div>
        </div>

        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px;">
            <a href="index.php?page=clients" class="btn btn-secondary flex align-center gap-1.5" style="border-radius: 8px; font-size: 0.82rem; font-weight: 600; padding: 8px 14px; text-decoration: none;">
                <i data-lucide="building-2" style="width: 15px; height: 15px;"></i>
                <span>Clients Directory</span>
            </a>

            <a href="index.php?page=sub_partners&action=export_sub_partners_csv" class="btn btn-secondary flex align-center gap-1.5" style="border-radius: 8px; font-size: 0.82rem; font-weight: 600; padding: 8px 14px; text-decoration: none;">
                <i data-lucide="download" style="width: 15px; height: 15px;"></i>
                <span>Export CSV</span>
            </a>

            <?php if ($is_super_admin): ?>
                <button type="button" onclick="openCustomColumnsModal()" class="btn btn-secondary flex align-center gap-1.5" style="border-radius: 8px; font-size: 0.82rem; font-weight: 600; padding: 8px 14px; border-color: #6366f1; color: #6366f1; background: rgba(99, 102, 241, 0.06);">
                    <i data-lucide="sliders" style="width: 15px; height: 15px;"></i>
                    <span>Manage Custom Columns</span>
                    <span class="badge" style="background: #6366f1; color: #fff; font-size: 0.65rem; padding: 2px 5px; border-radius: 4px;"><?php echo count($custom_fields_def); ?></span>
                </button>
            <?php endif; ?>

            <button type="button" onclick="openAddPartnerModal()" class="btn btn-primary flex align-center gap-1.5" style="border-radius: 8px; font-size: 0.82rem; font-weight: 700; padding: 8px 16px; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border: none; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35); color: #fff;">
                <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                <span>+ Add Sub-Partner</span>
            </button>
        </div>
    </div>

    <!-- NOTIFICATION BANNERS -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?>" style="border-radius: 10px; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1.25rem;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i data-lucide="<?php echo ($message_type === 'success') ? 'check-circle' : (($message_type === 'warning') ? 'alert-triangle' : 'alert-circle'); ?>" style="width: 18px; height: 18px;"></i>
                <div style="font-size: 0.85rem; font-weight: 600;"><?php echo $message; ?></div>
            </div>
            <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 1.1rem; cursor: pointer; color: inherit; line-height: 1;">&times;</button>
        </div>
    <?php endif; ?>

    <!-- KPI STATS CARDS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; margin-bottom: 1.5rem;">
        
        <!-- Total Partners -->
        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(99, 102, 241, 0.12); color: #6366f1; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="users" style="width: 22px; height: 22px;"></i>
            </div>
            <div>
                <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Total Sub-Partners</div>
                <div style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); line-height: 1.2;"><?php echo number_format($total_partners_count); ?></div>
                <div style="font-size: 0.7rem; color: #10b981; font-weight: 600; margin-top: 2px;">Registered in Directory</div>
            </div>
        </div>

        <!-- Active Partners -->
        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(16, 185, 129, 0.12); color: #10b981; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="check-check" style="width: 22px; height: 22px;"></i>
            </div>
            <div>
                <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Active Partners</div>
                <div style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); line-height: 1.2;"><?php echo number_format($active_partners_count); ?></div>
                <div style="font-size: 0.7rem; color: var(--text-muted); font-weight: 600; margin-top: 2px;">
                    <?php echo $total_partners_count > 0 ? round(($active_partners_count / $total_partners_count) * 100) : 0; ?>% Operational
                </div>
            </div>
        </div>

        <!-- Linked Clients / Licenses -->
        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(245, 158, 11, 0.12); color: #f59e0b; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="shield-check" style="width: 22px; height: 22px;"></i>
            </div>
            <div>
                <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Linked Licenses / Clients</div>
                <div style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); line-height: 1.2;"><?php echo number_format($total_linked_clients_count); ?></div>
                <div style="font-size: 0.7rem; color: #f59e0b; font-weight: 600; margin-top: 2px;">Issued via Channel</div>
            </div>
        </div>

        <!-- Top Channel Partner -->
        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(139, 92, 246, 0.12); color: #8b5cf6; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="award" style="width: 22px; height: 22px;"></i>
            </div>
            <div style="min-width: 0;">
                <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Top Channel Partner</div>
                <div style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($top_partner_name); ?>">
                    <?php echo htmlspecialchars($top_partner_name); ?>
                </div>
                <div style="font-size: 0.7rem; color: #8b5cf6; font-weight: 700; margin-top: 2px;">
                    ⭐ <?php echo number_format($top_partner_clients); ?> Client Licenses
                </div>
            </div>
        </div>

    </div>

    <!-- FILTER & SEARCH BAR -->
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <form method="GET" action="index.php" style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px;">
            <input type="hidden" name="page" value="sub_partners">

            <!-- Search Keyword -->
            <div style="flex: 1; min-width: 240px; position: relative;">
                <i data-lucide="search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-muted);"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Search partner code, name, phone, city..." class="form-control" style="padding-left: 36px; height: 40px; border-radius: 8px; font-size: 0.84rem;">
            </div>

            <!-- Status Filter -->
            <div style="width: 140px;">
                <select name="status" class="form-control" style="height: 40px; border-radius: 8px; font-size: 0.84rem;">
                    <option value="">All Statuses</option>
                    <option value="Active" <?php echo ($status_f === 'Active') ? 'selected' : ''; ?>>🟢 Active</option>
                    <option value="Inactive" <?php echo ($status_f === 'Inactive') ? 'selected' : ''; ?>>⚪ Inactive</option>
                </select>
            </div>

            <!-- City Filter -->
            <div style="width: 160px;">
                <select name="city" class="form-control" style="height: 40px; border-radius: 8px; font-size: 0.84rem;">
                    <option value="">All Cities</option>
                    <?php foreach ($distinct_cities as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($city_f === $c) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Action buttons -->
            <button type="submit" class="btn btn-primary" style="height: 40px; padding: 0 16px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; background: #6366f1; border: none; color: #fff;">
                Apply Filters
            </button>

            <?php if (!empty($search_q) || !empty($status_f) || !empty($city_f)): ?>
                <a href="index.php?page=sub_partners" class="btn btn-secondary" style="height: 40px; padding: 0 14px; border-radius: 8px; font-size: 0.82rem; font-weight: 600; display: inline-flex; align-items: center;">
                    Reset
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- SUB PARTNERS TABLE CARD -->
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.05);">
        
        <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <div style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <span>Partner Directory</span>
                <span class="badge" style="background: rgba(99, 102, 241, 0.12); color: #6366f1; font-size: 0.72rem; padding: 2px 8px; border-radius: 6px; font-weight: 700;">
                    <?php echo count($partners_list); ?> Found
                </span>
            </div>
            <div style="font-size: 0.75rem; color: var(--text-muted);">
                Showing all active channel dealers & sub-partners
            </div>
        </div>

        <div class="table-responsive" style="max-height: 720px; overflow-y: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.82rem; margin: 0;">
                <thead style="position: sticky; top: 0; background: var(--bg-card); z-index: 2; border-bottom: 2px solid var(--border-color);">
                    <tr style="text-align: left; color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em;">
                        <th style="padding: 12px 14px;">Partner Code</th>
                        <th style="padding: 12px 14px;">Firm / Partner Name</th>
                        <th style="padding: 12px 14px;">Contact Person & Mobile</th>
                        <th style="padding: 12px 14px;">City / State</th>
                        <th style="padding: 12px 14px; text-align: center;">Linked Clients</th>
                        
                        <!-- DYNAMIC CUSTOM COLUMNS HEADERS -->
                        <?php foreach ($custom_fields_def as $cf): ?>
                            <th style="padding: 12px 14px; white-space: nowrap; color: #6366f1;">
                                <i data-lucide="tag" style="width: 11px; height: 11px; display: inline-block; vertical-align: middle;"></i>
                                <?php echo htmlspecialchars($cf['field_label']); ?>
                            </th>
                        <?php endforeach; ?>

                        <th style="padding: 12px 14px; text-align: center;">Status</th>
                        <th style="padding: 12px 14px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($partners_list)): ?>
                        <tr>
                            <td colspan="<?php echo 7 + count($custom_fields_def); ?>" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;">
                                    <i data-lucide="handshake" style="width: 48px; height: 48px; stroke-width: 1.2; color: var(--text-muted); opacity: 0.4;"></i>
                                    <div style="font-size: 1rem; font-weight: 700; color: var(--text-main);">No Sub-Partners Found</div>
                                    <div style="font-size: 0.8rem; max-width: 400px;">No partner records matched your search filters. Click the button below to register a new sub-partner.</div>
                                    <button type="button" onclick="openAddPartnerModal()" class="btn btn-primary btn-sm" style="margin-top: 8px; background: #6366f1; border: none; border-radius: 6px; padding: 6px 14px; color: #fff;">
                                        + Add First Sub-Partner
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($partners_list as $p): ?>
                            <?php 
                                $codeKey = strtoupper(trim($p['partner_code']));
                                $clientCount = $client_counts_by_code[$codeKey] ?? 0;
                                $cfVals = !empty($p['custom_fields']) ? json_decode($p['custom_fields'], true) : [];
                                if (!is_array($cfVals)) $cfVals = [];
                                $isActive = (strtolower($p['status'] ?? '') === 'active');
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.15s;" onmouseover="this.style.background='var(--border-card)'" onmouseout="this.style.background='transparent'">
                                
                                <!-- Code -->
                                <td style="padding: 12px 14px; vertical-align: middle;">
                                    <span style="font-family: monospace; font-size: 0.8rem; font-weight: 700; background: rgba(99, 102, 241, 0.08); color: #4338ca; padding: 4px 8px; border-radius: 6px; border: 1px solid rgba(99, 102, 241, 0.2);">
                                        <?php echo htmlspecialchars($p['partner_code']); ?>
                                    </span>
                                </td>

                                <!-- Firm / Partner Name -->
                                <td style="padding: 12px 14px; vertical-align: middle;">
                                    <div style="font-weight: 700; color: var(--text-main); font-size: 0.86rem;">
                                        <?php echo htmlspecialchars($p['partner_name']); ?>
                                    </div>
                                    <?php if (!empty($p['gstin'])): ?>
                                        <div style="font-size: 0.7rem; color: var(--text-muted); font-family: monospace; margin-top: 2px;">
                                            GST: <?php echo htmlspecialchars($p['gstin']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Contact Person & Phone -->
                                <td style="padding: 12px 14px; vertical-align: middle;">
                                    <div style="font-weight: 600; color: var(--text-main);">
                                        <?php echo htmlspecialchars($p['contact_person'] ?: 'N/A'); ?>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px; margin-top: 3px;">
                                        <?php if (!empty($p['mobile'])): ?>
                                            <a href="tel:<?php echo htmlspecialchars($p['mobile']); ?>" style="color: var(--text-muted); font-size: 0.74rem; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;">
                                                <i data-lucide="phone" style="width: 11px; height: 11px;"></i>
                                                <?php echo htmlspecialchars($p['mobile']); ?>
                                            </a>
                                            <!-- WhatsApp Chat Shortcut -->
                                            <?php 
                                                $waNum = preg_replace('/[^0-9]/', '', $p['mobile']);
                                                if (strlen($waNum) === 10) $waNum = '91' . $waNum;
                                            ?>
                                            <a href="https://wa.me/<?php echo $waNum; ?>" target="_blank" title="WhatsApp Chat with Partner" style="color: #10b981; font-size: 0.75rem; text-decoration: none;">
                                                <i data-lucide="message-circle" style="width: 13px; height: 13px;"></i>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.72rem;">No mobile</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- City & State -->
                                <td style="padding: 12px 14px; vertical-align: middle;">
                                    <div style="font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 4px;">
                                        <i data-lucide="map-pin" style="width: 12px; height: 12px; color: var(--text-muted);"></i>
                                        <span><?php echo htmlspecialchars($p['city'] ?: 'N/A'); ?></span>
                                    </div>
                                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-left: 16px;">
                                        <?php echo htmlspecialchars($p['state'] ?: 'Uttar Pradesh'); ?>
                                    </div>
                                </td>

                                <!-- Linked Clients Count -->
                                <td style="padding: 12px 14px; vertical-align: middle; text-align: center;">
                                    <?php if ($clientCount > 0): ?>
                                        <a href="index.php?page=clients&search=<?php echo urlencode($p['partner_code']); ?>" class="badge" title="View all <?php echo $clientCount; ?> clients linked to <?php echo htmlspecialchars($p['partner_name']); ?>" style="text-decoration: none; background: rgba(16, 185, 129, 0.12); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.75rem; padding: 4px 10px; border-radius: 20px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px; transition: transform 0.15s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                            <i data-lucide="building-2" style="width: 12px; height: 12px;"></i>
                                            <span><?php echo number_format($clientCount); ?> Clients</span>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.75rem; font-weight: 500;">
                                            0 Clients
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- DYNAMIC CUSTOM COLUMNS VALUES -->
                                <?php foreach ($custom_fields_def as $cf): ?>
                                    <?php 
                                        $fK = $cf['field_key'];
                                        $val = $p[$fK] ?? ($cfVals[$fK] ?? '');
                                    ?>
                                    <td style="padding: 12px 14px; vertical-align: middle;">
                                        <?php if (!empty($val)): ?>
                                            <span style="font-size: 0.8rem; font-weight: 600; color: var(--text-main);">
                                                <?php echo htmlspecialchars($val); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.72rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>

                                <!-- Status -->
                                <td style="padding: 12px 14px; vertical-align: middle; text-align: center;">
                                    <?php if ($isActive): ?>
                                        <span style="display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25);">
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; background: rgba(107, 114, 128, 0.12); color: #6b7280; border: 1px solid rgba(107, 114, 128, 0.25);">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td style="padding: 12px 14px; vertical-align: middle; text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px;">
                                        <button type="button" onclick="openEditPartnerModal(<?php echo $p['id']; ?>)" class="btn btn-sm btn-secondary" title="Edit Partner" style="padding: 5px 8px; border-radius: 6px; font-size: 0.75rem;">
                                            <i data-lucide="edit-2" style="width: 13px; height: 13px;"></i>
                                        </button>

                                        <?php if ($clientCount > 0): ?>
                                            <a href="index.php?page=clients&search=<?php echo urlencode($p['partner_code']); ?>" class="btn btn-sm btn-secondary" title="View Linked Clients Directory" style="padding: 5px 8px; border-radius: 6px; font-size: 0.75rem; text-decoration: none;">
                                                <i data-lucide="external-link" style="width: 13px; height: 13px;"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($is_admin): ?>
                                            <form method="POST" action="index.php?page=sub_partners" onsubmit="return confirm('Are you sure you want to remove or inactivate sub-partner: <?php echo addslashes($p['partner_name']); ?>?');" style="display: inline-block; margin: 0;">
                                                <input type="hidden" name="action" value="delete_sub_partner">
                                                <input type="hidden" name="partner_id" value="<?php echo $p['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-secondary" title="Delete or Inactivate Partner" style="padding: 5px 8px; border-radius: 6px; font-size: 0.75rem; color: #ef4444;">
                                                    <i data-lucide="trash-2" style="width: 13px; height: 13px;"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

</div>

<!-- ========================================================================= -->
<!-- MODAL 1: ADD / EDIT SUB-PARTNER MODAL -->
<!-- ========================================================================= -->
<div id="partner_modal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.65); z-index: 9999; backdrop-filter: blur(4px); align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); width: 95%; max-width: 760px; max-height: 90vh; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 20px 40px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; animation: popIn 0.2s ease-out;">
        
        <!-- Modal Header -->
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: linear-gradient(to right, rgba(99, 102, 241, 0.04), transparent);">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(99, 102, 241, 0.12); color: #6366f1; display: flex; align-items: center; justify-content: center;">
                    <i id="partner_modal_icon" data-lucide="handshake" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h3 id="partner_modal_title" style="margin: 0; font-size: 1.15rem; font-weight: 800; color: var(--text-main);">
                        Add Sub-Partner
                    </h3>
                    <p style="margin: 2px 0 0 0; font-size: 0.76rem; color: var(--text-muted);">
                        Register partner details, code, territory, and custom attributes.
                    </p>
                </div>
            </div>
            <button type="button" onclick="closePartnerModal()" style="background: none; border: none; font-size: 1.4rem; color: var(--text-muted); cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <!-- Modal Body Form -->
        <form id="partner_form" method="POST" action="index.php?page=sub_partners" style="overflow-y: auto; padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
            <input type="hidden" name="action" value="save_sub_partner">
            <input type="hidden" id="pm_partner_id" name="partner_id" value="0">

            <!-- Section 1: Core Partner Identification -->
            <div style="background: rgba(99, 102, 241, 0.03); border: 1px solid rgba(99, 102, 241, 0.15); border-radius: 10px; padding: 12px 14px;">
                <div style="font-size: 0.78rem; font-weight: 700; color: #4338ca; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px;">
                    1. Identity & Business Details
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">
                            Sub-Partner Code <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="text" id="pm_partner_code" name="partner_code" placeholder="e.g. SSAA45323" required class="form-control text-xs font-mono" style="height: 38px; border-radius: 6px;">
                        <span style="font-size: 0.68rem; color: var(--text-muted);">Unique dealer/partner reference code.</span>
                    </div>

                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">
                            Firm / Partner Name <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="text" id="pm_partner_name" name="partner_name" placeholder="e.g. Marg ERP Communication" required class="form-control text-xs font-bold" style="height: 38px; border-radius: 6px;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">Contact Person</label>
                        <input type="text" id="pm_contact_person" name="contact_person" placeholder="e.g. Ajay Kumar" class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">Status</label>
                        <select id="pm_status" name="status" class="form-control text-xs font-semibold" style="height: 38px; border-radius: 6px;">
                            <option value="Active">🟢 Active</option>
                            <option value="Inactive">⚪ Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Section 2: Contact & Location Details -->
            <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 14px;">
                <div style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px;">
                    2. Contact & Address
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">Mobile Number</label>
                        <input type="text" id="pm_mobile" name="mobile" placeholder="e.g. 9876543210" class="form-control text-xs font-mono" style="height: 38px; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">Alternative Mobile</label>
                        <input type="text" id="pm_alt_mobile" name="alt_mobile" placeholder="e.g. 9170009697" class="form-control text-xs font-mono" style="height: 38px; border-radius: 6px;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">Email ID</label>
                        <input type="email" id="pm_email" name="email" placeholder="e.g. partner@example.com" class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">GSTIN (Optional)</label>
                        <input type="text" id="pm_gstin" name="gstin" placeholder="e.g. 09AAAAA0000A1Z5" class="form-control text-xs font-mono" style="height: 38px; border-radius: 6px;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">City</label>
                        <input type="text" id="pm_city" name="city" placeholder="e.g. Kanpur" class="form-control text-xs font-semibold" style="height: 38px; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">State</label>
                        <input type="text" id="pm_state" name="state" value="Uttar Pradesh" placeholder="e.g. Uttar Pradesh" class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                    </div>
                </div>

                <div style="margin-top: 10px;">
                    <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">Full Address</label>
                    <textarea id="pm_address" name="address" rows="2" placeholder="Office / Shop Address..." class="form-control text-xs" style="border-radius: 6px;"></textarea>
                </div>
            </div>

            <!-- Section 3: Dynamic Custom Fields Section -->
            <?php if (!empty($custom_fields_def)): ?>
                <div style="background: rgba(16, 185, 129, 0.03); border: 1px dashed rgba(16, 185, 129, 0.3); border-radius: 10px; padding: 12px 14px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <div style="font-size: 0.78rem; font-weight: 700; color: #059669; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px;">
                            <i data-lucide="sliders" style="width: 14px; height: 14px;"></i>
                            <span>3. Custom Attributes (Super Admin Defined)</span>
                        </div>
                        <?php if ($is_super_admin): ?>
                            <a href="javascript:void(0)" onclick="openCustomColumnsModal()" style="font-size: 0.72rem; color: #059669; text-decoration: underline; font-weight: 600;">
                                + Add Another Field
                            </a>
                        <?php endif; ?>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <?php foreach ($custom_fields_def as $cf): ?>
                            <?php 
                                $k = $cf['field_key'];
                                $lbl = $cf['field_label'];
                                $type = $cf['field_type'];
                                $pl = $cf['placeholder'] ?? '';
                                $req = ($cf['is_required'] == 1);
                            ?>
                            <div class="form-group m-0">
                                <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">
                                    <?php echo htmlspecialchars($lbl); ?>
                                    <?php if ($req): ?><span style="color: #ef4444;">*</span><?php endif; ?>
                                </label>

                                <?php if ($type === 'select'): ?>
                                    <?php 
                                        $optsArr = !empty($cf['options_json']) ? json_decode($cf['options_json'], true) : [];
                                        if (!is_array($optsArr)) $optsArr = [];
                                    ?>
                                    <select id="pm_cf_<?php echo $k; ?>" name="<?php echo $k; ?>" <?php echo $req ? 'required' : ''; ?> class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                                        <option value="">-- Select <?php echo htmlspecialchars($lbl); ?> --</option>
                                        <?php foreach ($optsArr as $op): ?>
                                            <option value="<?php echo htmlspecialchars($op); ?>"><?php echo htmlspecialchars($op); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($type === 'number'): ?>
                                    <input type="number" step="any" id="pm_cf_<?php echo $k; ?>" name="<?php echo $k; ?>" placeholder="<?php echo htmlspecialchars($pl); ?>" <?php echo $req ? 'required' : ''; ?> class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                                <?php elseif ($type === 'date'): ?>
                                    <input type="date" id="pm_cf_<?php echo $k; ?>" name="<?php echo $k; ?>" <?php echo $req ? 'required' : ''; ?> class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                                <?php elseif ($type === 'textarea'): ?>
                                    <textarea id="pm_cf_<?php echo $k; ?>" name="<?php echo $k; ?>" rows="2" placeholder="<?php echo htmlspecialchars($pl); ?>" <?php echo $req ? 'required' : ''; ?> class="form-control text-xs" style="border-radius: 6px;"></textarea>
                                <?php else: ?>
                                    <input type="text" id="pm_cf_<?php echo $k; ?>" name="<?php echo $k; ?>" placeholder="<?php echo htmlspecialchars($pl); ?>" <?php echo $req ? 'required' : ''; ?> class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Section 4: Remarks / Notes -->
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">Remarks / Internal Notes</label>
                <textarea id="pm_remarks" name="remarks" rows="2" placeholder="Additional notes about partner..." class="form-control text-xs" style="border-radius: 6px;"></textarea>
            </div>

            <!-- Modal Footer Buttons -->
            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 10px; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                <button type="button" onclick="closePartnerModal()" class="btn btn-secondary" style="border-radius: 6px; font-size: 0.82rem; padding: 8px 16px;">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="border-radius: 6px; font-size: 0.82rem; font-weight: 700; padding: 8px 20px; background: #6366f1; border: none; color: #fff;">
                    Save Sub-Partner
                </button>
            </div>
        </form>

    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: SUPER ADMIN CUSTOM COLUMNS & DYNAMIC FIELDS MANAGER -->
<!-- ========================================================================= -->
<?php if ($is_super_admin): ?>
<div id="custom_columns_modal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.65); z-index: 10000; backdrop-filter: blur(4px); align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); width: 95%; max-width: 680px; max-height: 90vh; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 20px 40px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; animation: popIn 0.2s ease-out;">
        
        <!-- Header -->
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: linear-gradient(to right, rgba(99, 102, 241, 0.06), transparent);">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(99, 102, 241, 0.12); color: #6366f1; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="sliders" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: var(--text-main);">
                        Dynamic Custom Columns
                    </h3>
                    <p style="margin: 2px 0 0 0; font-size: 0.76rem; color: var(--text-muted);">
                        Add or remove custom fields for Sub-Partners. New fields automatically appear in forms and table.
                    </p>
                </div>
            </div>
            <button type="button" onclick="closeCustomColumnsModal()" style="background: none; border: none; font-size: 1.4rem; color: var(--text-muted); cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <div style="overflow-y: auto; padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 1.25rem;">
            
            <!-- Form to Add New Column -->
            <div style="background: rgba(99, 102, 241, 0.04); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 10px; padding: 14px;">
                <div style="font-size: 0.82rem; font-weight: 800; color: #4338ca; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <i data-lucide="plus-circle" style="width: 15px; height: 15px;"></i>
                    <span>Add New Column / Field</span>
                </div>
                
                <form method="POST" action="index.php?page=sub_partners">
                    <input type="hidden" name="action" value="add_custom_field">

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-main); margin-bottom: 3px; display: block;">
                                Field Title / Column Header <span style="color: #ef4444;">*</span>
                            </label>
                            <input type="text" name="field_label" required placeholder="e.g. Bank Account No, PAN, Region" class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                        </div>

                        <div>
                            <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-main); margin-bottom: 3px; display: block;">Field Type</label>
                            <select name="field_type" id="new_field_type" onchange="toggleOptionsInput(this.value)" class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                                <option value="text">Text (Single Line)</option>
                                <option value="number">Numeric / Amount</option>
                                <option value="date">Date</option>
                                <option value="select">Dropdown Menu</option>
                                <option value="textarea">Long Text / Notes</option>
                            </select>
                        </div>
                    </div>

                    <div id="dropdown_options_wrap" style="display: none; margin-bottom: 10px;">
                        <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-main); margin-bottom: 3px; display: block;">
                            Dropdown Options (Comma separated)
                        </label>
                        <input type="text" name="options_list" placeholder="e.g. North Zone, South Zone, Central Zone" class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: flex-end;">
                        <div>
                            <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-main); margin-bottom: 3px; display: block;">Placeholder / Hint (Optional)</label>
                            <input type="text" name="placeholder" placeholder="e.g. Enter dealer ID..." class="form-control text-xs" style="height: 38px; border-radius: 6px;">
                        </div>
                        <button type="submit" class="btn btn-primary" style="height: 38px; padding: 0 16px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; background: #6366f1; border: none; color: #fff; white-space: nowrap;">
                            + Add Column
                        </button>
                    </div>
                </form>
            </div>

            <!-- Active Custom Fields List -->
            <div>
                <div style="font-size: 0.82rem; font-weight: 800; color: var(--text-main); margin-bottom: 8px;">
                    Existing Dynamic Columns (<?php echo count($custom_fields_def); ?>)
                </div>

                <?php if (empty($custom_fields_def)): ?>
                    <div style="font-size: 0.78rem; color: var(--text-muted); font-style: italic; padding: 10px; text-align: center; border: 1px dashed var(--border-color); border-radius: 8px;">
                        No custom columns created yet. Use the form above to add your first column.
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <?php foreach ($custom_fields_def as $cf): ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px;">
                                <div>
                                    <div style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
                                        <?php echo htmlspecialchars($cf['field_label']); ?>
                                    </div>
                                    <div style="font-size: 0.7rem; color: var(--text-muted); font-family: monospace;">
                                        Key: <code><?php echo htmlspecialchars($cf['field_key']); ?></code> | Type: <strong><?php echo htmlspecialchars($cf['field_type']); ?></strong>
                                    </div>
                                </div>
                                <form method="POST" action="index.php?page=sub_partners" onsubmit="return confirm('Remove custom column \'<?php echo addslashes($cf['field_label']); ?>\'? Existing stored data remains safely preserved in database.');" style="margin: 0;">
                                    <input type="hidden" name="action" value="delete_custom_field">
                                    <input type="hidden" name="field_id" value="<?php echo $cf['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary" style="color: #ef4444; padding: 4px 8px; font-size: 0.72rem; border-radius: 4px;">
                                        <i data-lucide="trash-2" style="width: 12px; height: 12px;"></i>
                                        <span>Remove</span>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end;">
            <button type="button" onclick="closeCustomColumnsModal()" class="btn btn-secondary" style="border-radius: 6px; font-size: 0.82rem; padding: 6px 16px;">
                Close
            </button>
        </div>

    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
        lucide.createIcons();
    }
});

function openAddPartnerModal() {
    document.getElementById('pm_partner_id').value = '0';
    document.getElementById('partner_modal_title').innerText = 'Add New Sub-Partner';
    document.getElementById('pm_partner_code').value = '';
    document.getElementById('pm_partner_code').readOnly = false;
    document.getElementById('pm_partner_name').value = '';
    document.getElementById('pm_contact_person').value = '';
    document.getElementById('pm_mobile').value = '';
    document.getElementById('pm_alt_mobile').value = '';
    document.getElementById('pm_email').value = '';
    document.getElementById('pm_city').value = '';
    document.getElementById('pm_state').value = 'Uttar Pradesh';
    document.getElementById('pm_address').value = '';
    document.getElementById('pm_gstin').value = '';
    document.getElementById('pm_status').value = 'Active';
    document.getElementById('pm_remarks').value = '';

    // Reset dynamic custom fields
    <?php foreach ($custom_fields_def as $cf): ?>
        var el_<?php echo $cf['field_key']; ?> = document.getElementById('pm_cf_<?php echo $cf['field_key']; ?>');
        if (el_<?php echo $cf['field_key']; ?>) el_<?php echo $cf['field_key']; ?>.value = '';
    <?php endforeach; ?>

    document.getElementById('partner_modal').style.display = 'flex';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function openEditPartnerModal(pId) {
    try {
        const res = await fetch('index.php?page=sub_partners&action=get_partner_details&id=' + pId);
        const data = await res.json();
        if (data.success && data.partner) {
            const p = data.partner;
            document.getElementById('pm_partner_id').value = p.id;
            document.getElementById('partner_modal_title').innerText = 'Edit Sub-Partner: ' + p.partner_name;
            document.getElementById('pm_partner_code').value = p.partner_code || '';
            document.getElementById('pm_partner_name').value = p.partner_name || '';
            document.getElementById('pm_contact_person').value = p.contact_person || '';
            document.getElementById('pm_mobile').value = p.mobile || '';
            document.getElementById('pm_alt_mobile').value = p.alt_mobile || '';
            document.getElementById('pm_email').value = p.email || '';
            document.getElementById('pm_city').value = p.city || '';
            document.getElementById('pm_state').value = p.state || 'Uttar Pradesh';
            document.getElementById('pm_address').value = p.address || '';
            document.getElementById('pm_gstin').value = p.gstin || '';
            document.getElementById('pm_status').value = p.status || 'Active';
            document.getElementById('pm_remarks').value = p.remarks || '';

            // Populate dynamic custom fields
            const cf = p.custom_fields_parsed || {};
            <?php foreach ($custom_fields_def as $cf): ?>
                var el_<?php echo $cf['field_key']; ?> = document.getElementById('pm_cf_<?php echo $cf['field_key']; ?>');
                if (el_<?php echo $cf['field_key']; ?>) {
                    var val = p['<?php echo $cf['field_key']; ?>'] || cf['<?php echo $cf['field_key']; ?>'] || '';
                    el_<?php echo $cf['field_key']; ?>.value = val;
                }
            <?php endforeach; ?>

            document.getElementById('partner_modal').style.display = 'flex';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        } else {
            alert('Failed to load partner details: ' + (data.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Network error loading partner details.');
    }
}

function closePartnerModal() {
    document.getElementById('partner_modal').style.display = 'none';
}

function openCustomColumnsModal() {
    const m = document.getElementById('custom_columns_modal');
    if (m) m.style.display = 'flex';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeCustomColumnsModal() {
    const m = document.getElementById('custom_columns_modal');
    if (m) m.style.display = 'none';
}

function toggleOptionsInput(val) {
    const wrap = document.getElementById('dropdown_options_wrap');
    if (wrap) {
        wrap.style.display = (val === 'select') ? 'block' : 'none';
    }
}
</script>
<style>
@keyframes popIn {
    0% { transform: scale(0.96); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}
</style>
