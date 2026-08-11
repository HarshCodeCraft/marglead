<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// Start session if not done already
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Helper: Native lightweight Excel XLSX file parser
function parseXLSX($filename) {
    $zip = new ZipArchive;
    if ($zip->open($filename) === TRUE) {
        // 1. Read shared strings XML if exists
        $sharedStrings = [];
        $stringsData = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsData) {
            $xml = simplexml_load_string($stringsData);
            if ($xml) {
                $ns = $xml->getDocNamespaces();
                $defaultNs = $ns[''] ?? '';
                $siElements = $xml->children($defaultNs)->si;
                foreach ($siElements as $si) {
                    $siChildren = $si->children($defaultNs);
                    if (isset($siChildren->t)) {
                        $sharedStrings[] = (string)$siChildren->t;
                    } elseif (isset($siChildren->r)) {
                        $tVal = '';
                        foreach ($siChildren->r as $r) {
                            $rChildren = $r->children($defaultNs);
                            if (isset($rChildren->t)) {
                                $tVal .= (string)$rChildren->t;
                            }
                        }
                        $sharedStrings[] = $tVal;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Read primary sheet XML
        $sheetData = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetData) {
            $zip->close();
            return false;
        }

        $xml = simplexml_load_string($sheetData);
        if (!$xml) {
            $zip->close();
            return false;
        }

        $ns = $xml->getDocNamespaces();
        $defaultNs = $ns[''] ?? '';
        $xmlRows = $xml->children($defaultNs)->sheetData->row;
        
        $rows = [];
        foreach ($xmlRows as $row) {
            $rowData = [];
            foreach ($row->c as $c) {
                $coord = (string)$c['r'];
                $colLetter = preg_replace('/[0-9]/', '', $coord);
                
                // Convert column letter to 0-based index (e.g. A->0, B->1, AA->26)
                $colIdx = 0;
                $len = strlen($colLetter);
                for ($i = 0; $i < $len; $i++) {
                    $colIdx = $colIdx * 26 + (ord($colLetter[$i]) - 64);
                }
                $colIdx = $colIdx - 1;

                $val = '';
                if (isset($c->v)) {
                    $val = (string)$c->v;
                    if (isset($c['t']) && (string)$c['t'] === 's') {
                        $val = $sharedStrings[(int)$val] ?? '';
                    }
                }
                $rowData[$colIdx] = trim($val);
            }
            
            // Normalize array keys to prevent index gaps
            $maxIdx = count($rowData) > 0 ? max(array_keys($rowData)) : -1;
            for ($i = 0; $i <= $maxIdx; $i++) {
                if (!isset($rowData[$i])) {
                    $rowData[$i] = '';
                }
            }
            ksort($rowData);
            $rows[] = $rowData;
        }
        
        $zip->close();
        return $rows;
    }
    return false;
}

// Handle CSV/Excel template download trigger
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $format = $_GET['format'] ?? 'csv';
    
    // Clear any previous output buffers to avoid prepending HTML to file downloads
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    if ($format === 'xlsx') {
        $file = __DIR__ . '/../../assets/sample_leads_template.xlsx';
        if (file_exists($file)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="sample_leads_template.xlsx"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        } else {
            $format = 'csv';
        }
    }
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="sample_leads_template.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Phone', 'Email', 'Company', 'Assigned To']);
        fputcsv($out, ['Amit Sharma', '919454883552', 'amit.sharma@apexpharma.com', 'Apex Pharma Solutions', 'AJAY RATHOUR']);
        fputcsv($out, ['Dr. Satish Verma', '919998877766', 'drverma@diagnostic.in', 'Dr. Verma Diagnostic Clinic', 'HARSH SAINI']);
        fputcsv($out, ['Rajesh Gupta', '919123456789', 'rgupta@metrochem.org', 'Metro Chemicals & Co.', 'MOIN KHAN']);
        fclose($out);
        exit;
    }
}

$message = '';
$message_type = '';
$parsed_rows = [];
$show_preview = false;

// Process File Upload (CSV or Excel XLSX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    // Increase environment resource limits for large spreadsheets (up to 5000+ lines)
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    if ($_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['excel_file']['tmp_name'];
        $file_name = $_FILES['excel_file']['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $rows_data = [];
        
        if ($ext === 'xlsx') {
            // Native Excel parsing
            $rows_data = parseXLSX($file_tmp);
            if ($rows_data === false) {
                $message = "Failed to parse Excel file XML structure.";
                $message_type = "danger";
            }
        } elseif ($ext === 'csv') {
            // Standard CSV parsing with delimiter detection
            $delimiter = ",";
            if (($handle = fopen($file_tmp, "r")) !== FALSE) {
                $first_line = fgets($handle);
                if ($first_line !== FALSE) {
                    $delimiters = [",", ";", "\t", "|"];
                    $counts = [];
                    foreach ($delimiters as $delim) {
                        $counts[$delim] = substr_count($first_line, $delim);
                    }
                    arsort($counts);
                    $detected = key($counts);
                    if ($counts[$detected] > 0) {
                        $delimiter = $detected;
                    }
                }
                rewind($handle);
                
                while (($data = fgetcsv($handle, 0, $delimiter, "\0")) !== FALSE) {
                    $rows_data[] = $data;
                }
                fclose($handle);
            } else {
                $message = "Failed to open CSV file.";
                $message_type = "danger";
            }
        } else {
            $message = "Invalid file type. Please upload a Microsoft Excel (.xlsx) or CSV (.csv) file.";
            $message_type = "danger";
        }
        
        // Map raw row data to database import models
        if (!empty($rows_data) && empty($message)) {
            // Discard header row
            $header = array_shift($rows_data);
            
            // Map header column indices dynamically
            if (!function_exists('sanitizeHeaderName')) {
                function sanitizeHeaderName($str) {
                    return preg_replace('/[^a-z0-9]/', '', strtolower($str));
                }
            }
            
            $field_mappings = [
                'name' => ['name', 'customername', 'clientname', 'fullname'],
                'phone' => ['phone', 'contact', 'contactnumber', 'phonenumber', 'mobile', 'mobilephone'],
                'email' => ['email', 'emailaddress', 'mail'],
                'company' => ['company', 'group', 'groupname', 'companyname', 'organization'],
                'assigned_to' => ['assignedto', 'assigned', 'operator', 'assignee', 'representative'],
                'address' => ['address', 'pincode', 'location'],
                'source' => ['source', 'leadsource'],
                'enq_for' => ['enqfor', 'product', 'enquiryfor', 'products'],
                'contact_person' => ['contactperson'],
                'remarks' => ['remark', 'remarks', 'note', 'notes'],
                'tags' => ['tag', 'tags']
            ];

            $col_indices = [
                'name' => 0,
                'phone' => 1,
                'email' => 2,
                'company' => 3,
                'assigned_to' => 4,
                'address' => -1,
                'source' => -1,
                'enq_for' => -1,
                'contact_person' => -1,
                'remarks' => -1,
                'tags' => -1
            ];
            
            foreach ($header as $idx => $header_val) {
                $sanitized = sanitizeHeaderName($header_val);
                if (empty($sanitized)) continue;
                
                foreach ($field_mappings as $field_key => $aliases) {
                    if (in_array($sanitized, $aliases)) {
                        $col_indices[$field_key] = $idx;
                        break;
                    }
                }
            }
            
            $row_idx = 1;
            foreach ($rows_data as $row) {
                $row_idx++;
                
                $name = trim($col_indices['name'] >= 0 ? ($row[$col_indices['name']] ?? '') : '');
                $phone = trim($col_indices['phone'] >= 0 ? ($row[$col_indices['phone']] ?? '') : '');
                $email = trim($col_indices['email'] >= 0 ? ($row[$col_indices['email']] ?? '') : '');
                $company = trim($col_indices['company'] >= 0 ? ($row[$col_indices['company']] ?? '') : '');
                $assigned_to = trim($col_indices['assigned_to'] >= 0 ? ($row[$col_indices['assigned_to']] ?? '') : '');
                $address = trim($col_indices['address'] >= 0 ? ($row[$col_indices['address']] ?? '') : '');
                $source = trim($col_indices['source'] >= 0 ? ($row[$col_indices['source']] ?? '') : '');
                $enq_for = trim($col_indices['enq_for'] >= 0 ? ($row[$col_indices['enq_for']] ?? '') : '');
                $contact_person = trim($col_indices['contact_person'] >= 0 ? ($row[$col_indices['contact_person']] ?? '') : '');
                $remarks = trim($col_indices['remarks'] >= 0 ? ($row[$col_indices['remarks']] ?? '') : '');
                $tags = trim($col_indices['tags'] >= 0 ? ($row[$col_indices['tags']] ?? '') : '');
                
                if (empty($name) && empty($phone)) {
                    continue; // Skip empty rows
                }
                
                // Cross reference database duplicates
                $duplicate_lead_id = null;
                $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                if ($db_connected && $pdo && !empty($clean_phone)) {
                    try {
                        $chk = $pdo->prepare("SELECT id FROM leads WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+91', '') LIKE ? LIMIT 1");
                        $chk->execute(['%' . substr($clean_phone, -10)]);
                        $lead = $chk->fetch();
                        if ($lead) {
                            $duplicate_lead_id = $lead['id'];
                        }
                    } catch (PDOException $e) {}
                }
                
                if (empty($name) && !empty($phone)) {
                    $name = 'Lead (' . $phone . ')';
                }

                $status = 'Valid';
                if (empty($phone)) {
                    $status = 'Missing Phone';
                }
                
                $parsed_rows[] = [
                    'row_num' => $row_idx,
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'company' => $company,
                    'assigned_to' => $assigned_to,
                    'address' => $address,
                    'source' => $source,
                    'enq_for' => $enq_for,
                    'contact_person' => $contact_person,
                    'remarks' => $remarks,
                    'tags' => $tags,
                    'duplicate_id' => $duplicate_lead_id,
                    'status' => $status
                ];
            }
            
            if (!empty($parsed_rows)) {
                $_SESSION['imported_leads'] = $parsed_rows;
                $show_preview = true;
                $message = "Spreadsheet file parsed successfully. Check the validation grid below before confirming import.";
                $message_type = "success";
            } else {
                $message = "The uploaded spreadsheet does not contain any valid client records.";
                $message_type = "danger";
            }
        }
    } else {
        $message = "File upload failed. Try again.";
        $message_type = "danger";
    }
}

// Process Confirmed Database Write Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import_action'])) {
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    $leads_to_import = $_SESSION['imported_leads'] ?? [];
    if (!empty($leads_to_import) && $db_connected && $pdo) {
        $inserted = 0;
        $updated = 0;
        
        try {
            $pdo->beginTransaction();
            
            $ins = $pdo->prepare("INSERT INTO leads (id, name, company, email, phone, address, source, tags, assigned_to, assigned_by, enq_for, contact_person, remarks, status, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', 'warm')");
            $upd = $pdo->prepare("UPDATE leads SET name = ?, company = ?, email = ?, address = ?, source = ?, tags = ?, assigned_to = ?, assigned_by = ?, enq_for = ?, contact_person = ?, remarks = ? WHERE id = ?");
            $log = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, 'Lead file registered via bulk spreadsheet import')");
            $generated_ids = [];
            foreach ($leads_to_import as $lead) {
                if ($lead['status'] !== 'Valid') {
                    continue; // Skip invalid records
                }
                
                $finalCompany = !empty($lead['company']) ? $lead['company'] : 'MARG ERP Softwares';
                $finalAssignee = !empty($lead['assigned_to']) ? $lead['assigned_to'] : '';
                if (empty($finalAssignee)) {
                    if ($db_connected && $pdo) {
                        try {
                            $stmtOp = $pdo->query("SELECT name FROM users WHERE status = 'Active' ORDER BY name ASC LIMIT 1");
                            $firstOp = $stmtOp->fetchColumn();
                            $finalAssignee = $firstOp ?: 'Amit Sen';
                        } catch (PDOException $e) {
                            $finalAssignee = 'Amit Sen';
                        }
                    } else {
                        $finalAssignee = 'Amit Sen';
                    }
                }
                
                if (!empty($lead['duplicate_id'])) {
                    $assigned_by = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
                    // Update matching profile
                    $upd->execute([
                        $lead['name'],
                        $finalCompany,
                        $lead['email'],
                        $lead['address'] ?: null,
                        $lead['source'] ?: 'Imported',
                        $lead['tags'] ?: null,
                        $finalAssignee,
                        $assigned_by,
                        $lead['enq_for'] ?: null,
                        $lead['contact_person'] ?: null,
                        $lead['remarks'] ?: null,
                        $lead['duplicate_id']
                    ]);
                    $updated++;
                } else {
                    // Create new lead record
                    do {
                        $newId = 'LD-' . sprintf('%06d', rand(100000, 999999));
                        $already_generated = isset($generated_ids[$newId]);
                        $exists = false;
                        if (!$already_generated) {
                            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE id = ?");
                            $check_stmt->execute([$newId]);
                            $exists = ($check_stmt->fetchColumn() > 0);
                        }
                    } while ($already_generated || $exists);
                    $generated_ids[$newId] = true;

                    $assigned_by = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
                    $ins->execute([
                        $newId,
                        $lead['name'],
                        $finalCompany,
                        $lead['email'],
                        $lead['phone'],
                        $lead['address'] ?: null,
                        $lead['source'] ?: 'Imported',
                        $lead['tags'] ?: null,
                        $finalAssignee,
                        $assigned_by,
                        $lead['enq_for'] ?: null,
                        $lead['contact_person'] ?: null,
                        $lead['remarks'] ?: null
                    ]);
                    
                    // Log to timeline
                    $log->execute([$newId, $_SESSION['user_name'] ?? 'System Admin']);
                    $inserted++;
                }
            }
            
            $pdo->commit();
            unset($_SESSION['imported_leads']);
            
            $_SESSION['flash_success'] = "Bulk import completed! Added {$inserted} new leads, updated {$updated} matching profile cards.";
            header("Location: index.php?page=leads");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Failed to write database updates: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "No spreadsheet records found in active session cache.";
        $message_type = "danger";
    }
}
?>

<div class="lead-import-container" style="max-width: 900px; margin: 0 auto;">
    <!-- Page Header -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;">Bulk Lead Import Wizard</h2>
            <p class="text-muted text-sm">Upload Excel (.xlsx) or CSV (.csv) spreadsheets to generate or merge lead opportunities.</p>
        </div>
        <a href="index.php?page=leads" class="btn btn-secondary text-sm">
            <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
            <span>Return to Directory</span>
        </a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; width: 100%; display: flex; font-size: 0.825rem;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Form layout upload -->
    <form action="index.php?page=lead_import" method="POST" enctype="multipart/form-data">
        <div class="grid" style="grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 1.5rem; align-items: start; margin-bottom: 2rem;">
            
            <!-- Left: Upload Box -->
            <div class="card p-6 flex flex-col align-center text-center justify-center pointer" style="border: 2px dashed var(--border-color); border-radius: var(--border-radius-md); height: 280px; transition: border-color var(--transition-fast);" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border-color)'" onclick="document.getElementById('import-file-selector').click();">
                <input type="file" name="excel_file" id="import-file-selector" class="hidden" accept=".xlsx, .csv" onchange="this.form.submit();">
                <div class="flex flex-col align-center justify-center">
                    <i data-lucide="upload-cloud" class="text-muted mb-4" style="width: 48px; height: 48px; color: var(--primary);"></i>
                    <h4 class="mb-2">Choose Excel or CSV spreadsheet</h4>
                    <p class="text-xs text-muted mb-4">Click to browse or drop your Excel (.xlsx) or CSV file from your device.</p>
                    <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);">Format supported: .XLSX / .CSV</span>
                </div>
            </div>

            <!-- Right: Guidelines panel -->
            <div class="card p-6" style="border: 1px solid var(--border-color); height: 280px; display: flex; flex-direction: column;">
                <h3 class="text-sm font-semibold mb-3">Formatting Guidelines</h3>
                <p class="text-xs text-muted mb-4">Ensure your spreadsheet spreadsheet matches these column configurations to avoid mapping check failures:</p>
                <ul class="flex flex-col gap-2 text-xs text-muted" style="flex: 1; overflow-y: auto;">
                    <li class="flex align-center gap-2"><i data-lucide="check" style="width: 14px; height: 14px; color: var(--success);"></i> <strong>Name</strong> (Required column)</li>
                    <li class="flex align-center gap-2"><i data-lucide="check" style="width: 14px; height: 14px; color: var(--success);"></i> <strong>Phone</strong> (Required, unique identifier)</li>
                    <li class="flex align-center gap-2"><i data-lucide="check" style="width: 14px; height: 14px; color: var(--success);"></i> <strong>Email</strong> (Optional, text string)</li>
                    <li class="flex align-center gap-2"><i data-lucide="check" style="width: 14px; height: 14px; color: var(--success);"></i> <strong>Company</strong> (Optional, group type)</li>
                    <li class="flex align-center gap-2"><i data-lucide="check" style="width: 14px; height: 14px; color: var(--success);"></i> <strong>Assigned To</strong> (Optional, executive name)</li>
                </ul>
                <div class="flex gap-2 mt-4" style="margin-top: auto;">
                    <a href="index.php?page=lead_import&action=download_template&format=xlsx" class="btn btn-secondary text-xs flex-1" style="padding: 0.5rem; justify-content: center; display: flex; align-items: center; gap: 4px;">
                        <i data-lucide="download" style="width: 12px; height: 12px;"></i>
                        <span>Excel (.xlsx)</span>
                    </a>
                    <a href="index.php?page=lead_import&action=download_template&format=csv" class="btn btn-secondary text-xs flex-1" style="padding: 0.5rem; justify-content: center; display: flex; align-items: center; gap: 4px;">
                        <i data-lucide="download" style="width: 12px; height: 12px;"></i>
                        <span>CSV (.csv)</span>
                    </a>
                </div>
            </div>

        </div>
    </form>

    <!-- Parsed Data Preview Panel (Dynamically loaded when file parsed) -->
    <?php if ($show_preview && !empty($parsed_rows)): ?>
        <div id="import-preview-panel" class="card p-6" style="border: 1px solid var(--border-color); animation: fadeIn 0.3s ease-in-out;">
            <div class="flex justify-between align-center mb-4">
                <div>
                    <h3 class="text-base font-semibold mb-1">Spreadsheet Validation Checkups</h3>
                    <p class="text-xs text-muted">Parsed <?php echo count($parsed_rows); ?> records. <?php if (count($parsed_rows) > 50) { echo "Showing first 50 rows for validation verification."; } else { echo "Review validation status before matching to directory."; } ?></p>
                </div>
                <form action="index.php?page=lead_import" method="POST">
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-secondary text-xs" style="padding: 0.5rem 1rem;" onclick="window.location.href='index.php?page=lead_import'">Cancel</button>
                        <button type="submit" name="confirm_import_action" class="btn btn-primary text-xs" style="padding: 0.5rem 1rem;">Confirm Import</button>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Customer Name</th>
                            <th>Company Name</th>
                            <th>Contact Number</th>
                            <th>Email</th>
                            <th>Duplicate Check</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $preview_limit = 50000;
                        $preview_rows = array_slice($parsed_rows, 0, $preview_limit);
                        foreach ($preview_rows as $row): 
                        ?>
                            <tr>
                                <td class="text-xs font-semibold">Row #<?php echo $row['row_num']; ?></td>
                                <td class="text-sm font-semibold"><?php echo htmlspecialchars($row['name'] ?: '---'); ?></td>
                                <td class="text-sm"><?php echo htmlspecialchars($row['company'] ?: 'MARG ERP Softwares'); ?></td>
                                <td class="text-sm"><?php echo htmlspecialchars($row['phone'] ?: '---'); ?></td>
                                <td class="text-sm text-muted text-xs"><?php echo htmlspecialchars($row['email'] ?: '---'); ?></td>
                                <td>
                                    <?php if ($row['status'] !== 'Valid'): ?>
                                        <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Invalid Syntax</span>
                                    <?php elseif (!empty($row['duplicate_id'])): ?>
                                        <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);"><i data-lucide="alert-triangle" style="width: 12px; height: 12px; display:inline; margin-right:3px; vertical-align:middle;"></i> Match (ID: <?php echo $row['duplicate_id']; ?>)</span>
                                    <?php else: ?>
                                        <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);"><i data-lucide="check-circle" style="width: 12px; height: 12px; display:inline; margin-right:3px; vertical-align:middle;"></i> Unique</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] !== 'Valid'): ?>
                                        <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);"><?php echo $row['status']; ?></span>
                                    <?php elseif (!empty($row['duplicate_id'])): ?>
                                        <span class="badge" style="--badge-bg: var(--info-light); --badge-color: var(--info);">Overwrite details</span>
                                    <?php else: ?>
                                        <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Importable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Initialize Lucide Icons in parsed panel
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
        
        // Auto scroll to validation panel if active
        const previewPanel = document.getElementById('import-preview-panel');
        if (previewPanel) {
            previewPanel.scrollIntoView({ behavior: 'smooth' });
        }
    });
</script>
