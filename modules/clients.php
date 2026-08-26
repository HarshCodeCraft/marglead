<?php
/**
 * Marg ERP CRM - Clients Directory & Account Overview
 * Dedicated Workspace to manage Client Directory (Old Client Database)
 * and Active CRM Accounts, featuring Bulk CSV/Excel Upload, Advanced Filters,
 * Column Header Customizer, Client Licence & AMC Update Window (Matching Design System),
 * and Edit Record Modals.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = $_SESSION['user_name'] ?? '';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin');

// Access Security Check: Restricted strictly to Super Admin and Admin
if (!$is_admin) {
    $_GET['requested'] = 'clients';
    include_once __DIR__ . '/access_denied.php';
    return;
}

// --------------------------------------------------------------------------
// 0. Action Handlers: Template Download & Directory CSV Export
// --------------------------------------------------------------------------

// Download Sample CSV Template
if (isset($_GET['action']) && $_GET['action'] === 'download_client_template') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="client_directory_template_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    
    $headers = [
        'S.No', 'S/W Type', 'CUSTOMER ID', 'Category', 'SubPartner Code', 'SubPartner Name', 
        'Party Name', 'CompanyUsing', 'Address', 'Mobile', 'EmailID', 
        'User', 'Type', 'NoOfUser', 'Contact Person', 'Due On', 
        'Act On', 'Days', 'Party Status', 'City', 'Transferred Party', 
        'OnlineZipCode', 'State', 'Home User', 'Software Trade', 'Version', 
        'Total Amount', 'Software HitDate', 'Wallet Id'
    ];
    fputcsv($output, $headers);
    
    // Sample Row 1
    fputcsv($output, [
        '1', 'Marg', '1352947', 'Category A', '', '', 
        'GANTAVYA PHARMACY', '4', 'SIS HOSPITAL 3 COM 1/9 AMBEDKAR PURAM AWAS VIKAS NO.3, KALYANPUR, KANPUR NAGAR-208017 UTTAR PRADESH, INDIA', '9340000000', 'sishospitalniramay@gmail.com', 
        'Multi User', 'Marg ERP Silver', '2', 'Mr. RAJESH', '', 
        '', '-559', 'Running', 'Kanpur', 'No', 
        '208017', 'Uttar Pradesh', 'No', 'Pharmaceutical & Chemicals', '', 
        '4661.00', '', ''
    ]);
    
    // Sample Row 2
    fputcsv($output, [
        '2', 'Marg', '1352948', 'Category B', 'SP-01', 'North Zone Partner', 
        'APEX MEDICAL STORE', '2', 'Shop No. 12, Main Market, Civil Lines, Kanpur-208001', '9876543210', 'apexmedical@gmail.com', 
        'Single User', 'Marg ERP Basic', '1', 'Dr. ANIL SHARMA', '2026-08-15', 
        '2026-07-20', '25', 'Running', 'Kanpur', 'No', 
        '208001', 'Uttar Pradesh', 'No', 'Pharmaceutical & Chemicals', '9.0', 
        '3500.00', '2026-07-01', 'W-9901'
    ]);
    
    fclose($output);
    exit;
}

// Export Client Directory Records to CSV
if (isset($_GET['action']) && $_GET['action'] === 'export_client_directory') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="client_directory_export_' . date('Y-m-d_H-i') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    
    $headers = [
        'S.No', 'S/W Type', 'CUSTOMER ID', 'Category', 'SubPartner Code', 'SubPartner Name', 
        'Party Name', 'CompanyUsing', 'Address', 'Mobile', 'EmailID', 
        'User', 'Type', 'NoOfUser', 'Contact Person', 'Due On', 
        'Act On', 'Days', 'Party Status', 'City', 'Transferred Party', 
        'OnlineZipCode', 'State', 'Home User', 'Software Trade', 'Version', 
        'Total Amount', 'Software HitDate', 'Wallet Id'
    ];
    fputcsv($output, $headers);
    
    if ($db_connected && $pdo) {
        $stmt = $pdo->query("SELECT * FROM client_directory ORDER BY id ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['sno'], $row['sw_type'], $row['customer_id'], ($row['category'] ?? 'Category A'), $row['subpartner_code'], $row['subpartner_name'],
                $row['party_name'], $row['company_using'], $row['address'], $row['mobile'], $row['email'],
                $row['user_type'], $row['software_type'], $row['no_of_users'], $row['contact_person'], $row['due_on'],
                $row['act_on'], $row['days'], $row['party_status'], $row['city'], $row['transferred_party'],
                $row['online_zip_code'], $row['state'], $row['home_user'], $row['software_trade'], $row['version'],
                $row['total_amount'], $row['software_hit_date'], $row['wallet_id']
            ]);
        }
    }
    fclose($output);
    exit;
}

// --------------------------------------------------------------------------
// 1. Helper: Native XLSX Parser for Excel Files
// --------------------------------------------------------------------------
function parseClientXLSX($filename) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive;
    if ($zip->open($filename) === TRUE) {
        $sharedStrings = [];
        $stringsData = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsData) {
            $xml = @simplexml_load_string($stringsData);
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

        $sheetData = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetData) {
            $zip->close();
            return false;
        }

        $xml = @simplexml_load_string($sheetData);
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

// --------------------------------------------------------------------------
// 2. Action Handlers: Import, Edit & AMC Update
// --------------------------------------------------------------------------
$import_result = null;

// Bulk Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_client_directory') {
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    $duplicate_option = $_POST['duplicate_option'] ?? 'update';
    
    if (isset($_FILES['client_file']) && $_FILES['client_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['client_file']['tmp_name'];
        $file_name = $_FILES['client_file']['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $parsed_rows = [];
        
        if ($ext === 'csv') {
            if (($handle = fopen($file_tmp, "r")) !== FALSE) {
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
                while (($data = fgetcsv($handle, 5000, ",")) !== FALSE) {
                    $parsed_rows[] = array_map('trim', $data);
                }
                fclose($handle);
            }
        } elseif ($ext === 'xlsx') {
            $parsed_rows = parseClientXLSX($file_tmp);
        }

        if (!empty($parsed_rows) && is_array($parsed_rows)) {
            $header_map = [];
            $first_row = array_shift($parsed_rows);
            
            foreach ($first_row as $idx => $hName) {
                $cleanH = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $hName)));
                $header_map[$cleanH] = $idx;
            }

            $getValue = function($row, $headerKeys, $fallbackIdx) use ($header_map) {
                foreach ($headerKeys as $key) {
                    $cleanKey = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $key)));
                    if (isset($header_map[$cleanKey]) && isset($row[$header_map[$cleanKey]])) {
                        return trim($row[$header_map[$cleanKey]]);
                    }
                }
                return isset($row[$fallbackIdx]) ? trim($row[$fallbackIdx]) : '';
            };

            $inserted_count = 0;
            $updated_count = 0;
            $skipped_count = 0;

            if ($pdo) {
                $pdo->beginTransaction();
                try {
                    $checkStmt = $pdo->prepare("SELECT id FROM client_directory WHERE customer_id = ?");
                    
                    $insertStmt = $pdo->prepare("
                        INSERT INTO client_directory (
                            sno, sw_type, customer_id, category, subpartner_code, subpartner_name, party_name, company_using,
                            address, mobile, email, user_type, software_type, no_of_users, contact_person,
                            due_on, act_on, days, party_status, city, transferred_party, online_zip_code,
                            state, home_user, software_trade, version, total_amount, software_hit_date, wallet_id
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?
                        )
                    ");

                    $updateStmt = $pdo->prepare("
                        UPDATE client_directory SET
                            sno = ?, sw_type = ?, category = ?, subpartner_code = ?, subpartner_name = ?, party_name = ?, company_using = ?,
                            address = ?, mobile = ?, email = ?, user_type = ?, software_type = ?, no_of_users = ?, contact_person = ?,
                            due_on = ?, act_on = ?, days = ?, party_status = ?, city = ?, transferred_party = ?, online_zip_code = ?,
                            state = ?, home_user = ?, software_trade = ?, version = ?, total_amount = ?, software_hit_date = ?, wallet_id = ?
                        WHERE customer_id = ?
                    ");

                    $formatDate = function($val) {
                        if (empty($val) || strpos($val, '#') !== false) return null;
                        $t = strtotime($val);
                        return $t ? date('Y-m-d', $t) : null;
                    };

                    foreach ($parsed_rows as $row) {
                        if (empty(array_filter($row))) continue;

                        $customer_id = $getValue($row, ['CUSTOMER ID', 'customer_id', 'customerid', 'cust_id'], 2);
                        $party_name = $getValue($row, ['Party Name', 'party_name', 'party'], 5);

                        if (empty($customer_id)) {
                            if (empty($party_name)) {
                                $skipped_count++;
                                continue;
                            }
                            $customer_id = 'CUST-' . strtoupper(substr(md5($party_name . microtime()), 0, 8));
                        }

                        $sno = intval($getValue($row, ['S.No', 'sno', 's_no'], 0)) ?: null;
                        $sw_type = $getValue($row, ['S/W Type', 'sw_type', 'software_type'], 1) ?: 'Marg';
                        $category = $getValue($row, ['Category', 'category', 'client_category', 'party_category', 'cat'], -1) ?: 'Category A';
                        $subpartner_code = $getValue($row, ['SubPartner Code', 'subpartner_code'], 3) ?: null;
                        $subpartner_name = $getValue($row, ['SubPartner Name', 'subpartner_name'], 4) ?: null;
                        $company_using = $getValue($row, ['CompanyUsing', 'company_using'], 6) ?: null;
                        $address = $getValue($row, ['Address', 'address'], 7) ?: null;
                        
                        $rawMobile = $getValue($row, ['Mobile', 'mobile', 'phone'], 8);
                        if (preg_match('/[eE]\+/', $rawMobile)) {
                            $mobile = sprintf('%.0f', floatval($rawMobile));
                        } else {
                            $mobile = preg_replace('/[^0-9+]/', '', $rawMobile) ?: $rawMobile;
                        }

                        $email = $getValue($row, ['EmailID', 'email', 'emailid'], 9) ?: null;
                        $user_type = $getValue($row, ['User', 'user_type', 'user'], 10) ?: null;
                        $software_type = $getValue($row, ['Type', 'software_type'], 11) ?: null;
                        $no_of_users = intval($getValue($row, ['NoOfUser', 'no_of_users', 'no of user'], 12)) ?: 1;
                        $contact_person = $getValue($row, ['Contact Person', 'contact_person'], 13) ?: null;
                        
                        $due_on = $formatDate($getValue($row, ['Due On', 'due_on'], 14));
                        $act_on = $formatDate($getValue($row, ['Act On', 'act_on'], 15));
                        $days = intval($getValue($row, ['Days', 'days'], 16)) ?: 0;
                        $party_status = $getValue($row, ['Party Status', 'party_status'], 17) ?: 'Running';
                        $city = $getValue($row, ['City', 'city'], 18) ?: null;
                        $transferred_party = $getValue($row, ['Transferred Party', 'transferred_party'], 19) ?: 'No';
                        $online_zip_code = $getValue($row, ['OnlineZipCode', 'online_zip_code'], 20) ?: null;
                        $state = $getValue($row, ['State', 'state'], 21) ?: null;
                        $home_user = $getValue($row, ['Home User', 'home_user'], 22) ?: 'No';
                        $software_trade = $getValue($row, ['Software Trade', 'software_trade'], 23) ?: null;
                        $version = $getValue($row, ['Version', 'version'], 24) ?: null;
                        
                        $rawAmt = $getValue($row, ['Total Amount', 'total_amount', 'amount'], 25);
                        $total_amount = floatval(preg_replace('/[^0-9.]/', '', $rawAmt)) ?: 0.00;
                        
                        $software_hit_date = $formatDate($getValue($row, ['Software HitDate', 'software_hit_date'], 26));
                        $wallet_id = $getValue($row, ['Wallet Id', 'wallet_id'], 27) ?: null;

                        $checkStmt->execute([$customer_id]);
                        $exists = $checkStmt->fetch();

                        if ($exists) {
                            if ($duplicate_option === 'update') {
                                $updateStmt->execute([
                                    $sno, $sw_type, $category, $subpartner_code, $subpartner_name, $party_name, $company_using,
                                    $address, $mobile, $email, $user_type, $software_type, $no_of_users, $contact_person,
                                    $due_on, $act_on, $days, $party_status, $city, $transferred_party, $online_zip_code,
                                    $state, $home_user, $software_trade, $version, $total_amount, $software_hit_date, $wallet_id,
                                    $customer_id
                                ]);
                                $updated_count++;
                            } else {
                                $skipped_count++;
                            }
                        } else {
                            $insertStmt->execute([
                                $sno, $sw_type, $customer_id, $category, $subpartner_code, $subpartner_name, $party_name, $company_using,
                                $address, $mobile, $email, $user_type, $software_type, $no_of_users, $contact_person,
                                $due_on, $act_on, $days, $party_status, $city, $transferred_party, $online_zip_code,
                                $state, $home_user, $software_trade, $version, $total_amount, $software_hit_date, $wallet_id
                            ]);
                            $inserted_count++;
                        }
                    }
                    $pdo->commit();
                    $import_result = [
                        'success' => true,
                        'message' => "Bulk Client Directory Import completed successfully! New Imported: <strong>{$inserted_count}</strong>, Updated: <strong>{$updated_count}</strong>, Skipped: <strong>{$skipped_count}</strong> records."
                    ];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $import_result = [
                        'success' => false,
                        'message' => "Import Database Error: " . $e->getMessage()
                    ];
                }
            }
        } else {
            $import_result = ['success' => false, 'message' => "Could not read spreadsheet data. Please ensure file format is a valid CSV or Excel XLSX."];
        }
    } else {
        $import_result = ['success' => false, 'message' => "Please choose a valid CSV or Excel file to upload."];
    }
}

// Edit Record Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_client_directory') {
    $id = intval($_POST['client_db_id'] ?? 0);
    $customer_id = trim($_POST['customer_id'] ?? '');
    $party_name = trim($_POST['party_name'] ?? '');
    $category = trim($_POST['category'] ?? 'Category A');
    
    if ($id > 0 && !empty($party_name) && $pdo) {
        try {
            $stmt = $pdo->prepare("
                UPDATE client_directory SET
                    sw_type = ?, customer_id = ?, category = ?, subpartner_code = ?, subpartner_name = ?,
                    party_name = ?, company_using = ?, address = ?, mobile = ?, email = ?,
                    user_type = ?, software_type = ?, no_of_users = ?, contact_person = ?,
                    due_on = ?, act_on = ?, days = ?, party_status = ?, city = ?,
                    transferred_party = ?, online_zip_code = ?, state = ?, home_user = ?,
                    software_trade = ?, version = ?, total_amount = ?, software_hit_date = ?, wallet_id = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $_POST['sw_type'] ?? 'Marg',
                $customer_id,
                $category,
                $_POST['subpartner_code'] ?: null,
                $_POST['subpartner_name'] ?: null,
                $party_name,
                $_POST['company_using'] ?: null,
                $_POST['address'] ?: null,
                $_POST['mobile'] ?: null,
                $_POST['email'] ?: null,
                $_POST['user_type'] ?: null,
                $_POST['software_type'] ?: null,
                intval($_POST['no_of_users'] ?? 1),
                $_POST['contact_person'] ?: null,
                $_POST['due_on'] ?: null,
                $_POST['act_on'] ?: null,
                intval($_POST['days'] ?? 0),
                $_POST['party_status'] ?? 'Running',
                $_POST['city'] ?: null,
                $_POST['transferred_party'] ?? 'No',
                $_POST['online_zip_code'] ?: null,
                $_POST['state'] ?: null,
                $_POST['home_user'] ?? 'No',
                $_POST['software_trade'] ?: null,
                $_POST['version'] ?: null,
                floatval($_POST['total_amount'] ?? 0.00),
                $_POST['software_hit_date'] ?: null,
                $_POST['wallet_id'] ?: null,
                $id
            ]);
            
            $import_result = [
                'success' => true,
                'message' => "Client record for <strong>" . htmlspecialchars($party_name) . "</strong> updated successfully!"
            ];
        } catch (Exception $e) {
            $import_result = ['success' => false, 'message' => "Error updating client record: " . $e->getMessage()];
        }
    }
}

// AMC Update Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_client_amc') {
    $id = intval($_POST['amc_client_id'] ?? 0);
    $feed_amc = floatval($_POST['feed_amc_amount'] ?? 0.00);
    $final_amt = floatval($_POST['amc_final_amount'] ?? 0.00);
    $reasons = isset($_POST['reasons']) ? implode(', ', $_POST['reasons']) : '';

    if ($id > 0 && $pdo) {
        try {
            $stmt = $pdo->prepare("UPDATE client_directory SET total_amount = total_amount + ?, party_status = 'Running' WHERE id = ?");
            $stmt->execute([$final_amt, $id]);
            
            $import_result = [
                'success' => true,
                'message' => "AMC Update recorded successfully! New AMC Amount: <strong>₹" . number_format($final_amt, 2) . "</strong> added to client profile."
            ];
        } catch (Exception $e) {
            $import_result = ['success' => false, 'message' => "Error processing AMC update: " . $e->getMessage()];
        }
    }
}

// Client Account & Login Credentials Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_client_account') {
    $client_id = intval($_POST['client_id'] ?? 0);
    $customer_id = trim($_POST['customer_id'] ?? '');
    $party_name = trim($_POST['party_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $software_type = trim($_POST['software_type'] ?? 'Marg ERP Silver');
    $no_of_users = intval($_POST['no_of_users'] ?? 1);
    $due_on = trim($_POST['due_on'] ?? '');
    $total_amount = floatval($_POST['total_amount'] ?? 0.00);
    $party_status = trim($_POST['party_status'] ?? 'Running');
    $new_password = trim($_POST['password'] ?? '');
    $selected_modules = isset($_POST['modules']) && is_array($_POST['modules']) ? $_POST['modules'] : ['dashboard', 'quotation', 'payments', 'support', 'renewals', 'bot_flows'];

    if ($pdo && !empty($email)) {
        try {
            // 1. Update client_directory
            if ($client_id > 0) {
                $upd = $pdo->prepare("UPDATE client_directory SET 
                    customer_id = ?, party_name = ?, email = ?, software_type = ?, 
                    no_of_users = ?, due_on = ?, total_amount = ?, party_status = ? 
                    WHERE id = ?");
                $upd->execute([$customer_id, $party_name, $email, $software_type, $no_of_users, $due_on ?: null, $total_amount, $party_status, $client_id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO client_directory 
                    (customer_id, party_name, email, user_type, software_type, no_of_users, due_on, total_amount, party_status) 
                    VALUES (?, ?, ?, 'Registered Client', ?, ?, ?, ?, ?)");
                $ins->execute([$customer_id ?: ('CL-' . rand(10000, 99999)), $party_name, $email, $software_type, $no_of_users, $due_on ?: null, $total_amount, $party_status]);
            }

            // 2. Sync users table (Role = Client, credentials, and feature access permissions)
            $chkUser = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $chkUser->execute([$email]);
            $userExistsId = $chkUser->fetchColumn();

            $user_status = ($party_status === 'Running' || $party_status === 'Active') ? 'Active' : ($party_status === 'Pending Approval' ? 'Pending Approval' : 'Declined');
            $perms_json = json_encode(array_values(array_unique($selected_modules)));

            if ($userExistsId) {
                if (!empty($new_password)) {
                    $hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $uUpd = $pdo->prepare("UPDATE users SET name = ?, password = ?, role = 'Client', status = ?, permissions = ? WHERE id = ?");
                    $uUpd->execute([$party_name, $hash, $user_status, $perms_json, $userExistsId]);
                } else {
                    $uUpd = $pdo->prepare("UPDATE users SET name = ?, role = 'Client', status = ?, permissions = ? WHERE id = ?");
                    $uUpd->execute([$party_name, $user_status, $perms_json, $userExistsId]);
                }
            } else {
                $pass = !empty($new_password) ? $new_password : 'client123';
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $uIns = $pdo->prepare("INSERT INTO users (name, email, password, role, status, permissions) VALUES (?, ?, ?, 'Client', ?, ?)");
                $uIns->execute([$party_name, $email, $hash, $user_status, $perms_json]);
            }

            $import_result = [
                'success' => true,
                'message' => "Client Account, Login Credentials & Access Privileges updated successfully for <strong>" . htmlspecialchars($party_name) . "</strong>!"
            ];
        } catch (Exception $e) {
            $import_result = ['success' => false, 'message' => "Error updating Client Account: " . $e->getMessage()];
        }
    }
}

// --------------------------------------------------------------------------
// 3. Tab & View State Setup
// --------------------------------------------------------------------------
$active_tab = $_GET['tab'] ?? 'directory';

$search_query = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$category_filter = trim($_GET['category'] ?? '');
$trade_filter = trim($_GET['trade'] ?? '');
$product_filter = trim($_GET['product'] ?? '');
$operator_filter = trim($_GET['operator'] ?? '');

$page_num = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$limit = isset($_GET['limit']) ? $_GET['limit'] : 25;
if ($limit !== 'all') {
    $limit = max(10, intval($limit));
}

// --------------------------------------------------------------------------
// 4. Data Queries for Tab 1: Client Directory (client_directory table)
// --------------------------------------------------------------------------
$dir_total_count = 0;
$dir_running_count = 0;
$dir_total_val = 0;
$dir_trade_types = [];
$dir_categories = [];
$dir_records = [];
$dir_matching_count = 0;

if ($db_connected && $pdo) {
    try {
        $dir_total_count = $pdo->query("SELECT COUNT(*) FROM client_directory")->fetchColumn();
        $dir_running_count = $pdo->query("SELECT COUNT(*) FROM client_directory WHERE LOWER(party_status) = 'running'")->fetchColumn();
        $valSum = $pdo->query("SELECT SUM(total_amount) FROM client_directory")->fetchColumn();
        if ($valSum) $dir_total_val = floatval($valSum);

        $tradeStmt = $pdo->query("SELECT DISTINCT software_trade FROM client_directory WHERE software_trade IS NOT NULL AND software_trade != '' ORDER BY software_trade ASC");
        $dir_trade_types = $tradeStmt->fetchAll(PDO::FETCH_COLUMN);

        $catStmt = $pdo->query("SELECT DISTINCT category FROM client_directory WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
        $dir_categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

        $dir_where = [];
        $dir_params = [];

        if (!empty($search_query)) {
            $dir_where[] = "(party_name LIKE ? OR customer_id LIKE ? OR mobile LIKE ? OR email LIKE ? OR address LIKE ? OR city LIKE ? OR state LIKE ? OR contact_person LIKE ? OR category LIKE ?)";
            $st = '%' . $search_query . '%';
            for ($i = 0; $i < 9; $i++) $dir_params[] = $st;
        }

        if (!empty($status_filter)) {
            $dir_where[] = "LOWER(party_status) = ?";
            $dir_params[] = strtolower($status_filter);
        }

        if (!empty($category_filter)) {
            $dir_where[] = "LOWER(category) = ?";
            $dir_params[] = strtolower($category_filter);
        }

        if (!empty($trade_filter)) {
            $dir_where[] = "software_trade = ?";
            $dir_params[] = $trade_filter;
        }

        $where_dir_sql = !empty($dir_where) ? "WHERE " . implode(" AND ", $dir_where) : "";

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM client_directory {$where_dir_sql}");
        $countStmt->execute($dir_params);
        $dir_matching_count = $countStmt->fetchColumn();

        $dir_total_pages = 1;
        if ($limit !== 'all') {
            $dir_total_pages = ceil(max(1, $dir_matching_count) / $limit);
            if ($page_num > $dir_total_pages) $page_num = max(1, $dir_total_pages);
            $offset = ($page_num - 1) * $limit;
        }

        if ($limit === 'all') {
            $fetchSql = "SELECT * FROM client_directory {$where_dir_sql} ORDER BY id DESC";
            $stmt = $pdo->prepare($fetchSql);
            $stmt->execute($dir_params);
        } else {
            $fetchSql = "SELECT * FROM client_directory {$where_dir_sql} ORDER BY id DESC LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($fetchSql);
            $idx = 1;
            foreach ($dir_params as $pVal) {
                $stmt->bindValue($idx++, $pVal, PDO::PARAM_STR);
            }
            $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }

        $dir_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {}
}

// --------------------------------------------------------------------------
// 5. Data Queries for Tab 2: CRM Active Clients (leads table)
// --------------------------------------------------------------------------
$crm_total_count = 0;
$crm_installed_count = 0;
$crm_portfolio_val = 0;
$crm_records = [];
$crm_matching_count = 0;
$operators_list = [];

if ($db_connected && $pdo) {
    try {
        $opStmt = $pdo->query("SELECT name FROM users WHERE status = 'Active' ORDER BY name ASC");
        $operators_list = $opStmt->fetchAll(PDO::FETCH_COLUMN);

        $crm_total_count = $pdo->query("SELECT COUNT(*) FROM leads WHERE LOWER(status) IN ('won', 'closed_won', 'payment_received', 'install_pending', 'install_completed', 'training_completed', 'support', 'renewal')")->fetchColumn();
        $crm_installed_count = $pdo->query("SELECT COUNT(*) FROM leads WHERE LOWER(status) IN ('install_completed', 'training_completed', 'support')")->fetchColumn();
        $crmSum = $pdo->query("SELECT SUM(budget) FROM leads WHERE LOWER(status) IN ('won', 'closed_won', 'payment_received', 'install_pending', 'install_completed', 'training_completed', 'support', 'renewal')")->fetchColumn();
        if ($crmSum) $crm_portfolio_val = floatval($crmSum);

        $crm_where = ["LOWER(status) IN ('won', 'closed_won', 'payment_received', 'install_pending', 'install_completed', 'training_completed', 'support', 'renewal')"];
        $crm_params = [];

        if (!empty($search_query)) {
            $crm_where[] = "(name LIKE ? OR company LIKE ? OR phone LIKE ? OR email LIKE ? OR gst LIKE ? OR city LIKE ? OR address LIKE ?)";
            $st = '%' . $search_query . '%';
            for ($i = 0; $i < 7; $i++) $crm_params[] = $st;
        }

        if (!empty($status_filter)) {
            $crm_where[] = "LOWER(status) = ?";
            $crm_params[] = strtolower($status_filter);
        }

        if (!empty($product_filter)) {
            $crm_where[] = "(LOWER(enq_for) = ? OR LOWER(products) LIKE ?)";
            $crm_params[] = strtolower($product_filter);
            $crm_params[] = '%' . strtolower($product_filter) . '%';
        }

        if (!empty($operator_filter)) {
            $crm_where[] = "assigned_to = ?";
            $crm_params[] = $operator_filter;
        }

        $where_crm_sql = "WHERE " . implode(" AND ", $crm_where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM leads {$where_crm_sql}");
        $countStmt->execute($crm_params);
        $crm_matching_count = $countStmt->fetchColumn();

        $crm_total_pages = 1;
        if ($limit !== 'all') {
            $crm_total_pages = ceil(max(1, $crm_matching_count) / $limit);
            if ($page_num > $crm_total_pages) $page_num = max(1, $crm_total_pages);
            $offset = ($page_num - 1) * $limit;
        }

        if ($limit === 'all') {
            $fetchSql = "SELECT * FROM leads {$where_crm_sql} ORDER BY updated_at DESC";
            $stmt = $pdo->prepare($fetchSql);
            $stmt->execute($crm_params);
        } else {
            $fetchSql = "SELECT * FROM leads {$where_crm_sql} ORDER BY updated_at DESC LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($fetchSql);
            $idx = 1;
            foreach ($crm_params as $pVal) {
                $stmt->bindValue($idx++, $pVal, PDO::PARAM_STR);
            }
            $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }

        $crm_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {}
}

$products_options = [
    'Marg Basic', 'Marg Silver', 'Marg Gold', 'Marg Nano', 'Marg Hr', 
    'Marg Cloud', 'Marg Book Gold', 'Marg Book Silver', 'Marg Enterprises', 'Marg Mart', 'Marg Diamond'
];

function getClientsPageUrl($tab, $p, $limit) {
    $params = $_GET;
    $params['tab'] = $tab;
    $params['p'] = $p;
    $params['limit'] = $limit;
    return 'index.php?' . http_build_query($params);
}
?>

<div class="clients-container" style="max-width: 1400px; margin: 0 auto;">
    
    <!-- Toast Notification Banner for Operations -->
    <?php if ($import_result): ?>
        <div class="card p-4 mb-6" style="border: 1px solid <?php echo $import_result['success'] ? 'var(--success)' : 'var(--danger)'; ?>; background-color: <?php echo $import_result['success'] ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'; ?>; border-radius: var(--border-radius-md);">
            <div class="flex align-center gap-3">
                <i data-lucide="<?php echo $import_result['success'] ? 'check-circle' : 'alert-circle'; ?>" style="width: 24px; height: 24px; color: <?php echo $import_result['success'] ? 'var(--success)' : 'var(--danger)'; ?>;"></i>
                <div class="text-sm">
                    <?php echo $import_result['message']; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header Controls Row -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <div class="flex align-center gap-2 text-xs text-muted mb-1">
                <span>Management Hub</span>
                <i data-lucide="chevron-right" style="width: 12px; height: 12px;"></i>
                <span class="font-semibold text-main">Client Enterprise Directory</span>
            </div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 800; color: var(--text-main);" class="m-0">
                Clients Management Workspace
            </h2>
            <p class="text-muted text-sm m-0">Manage complete client directory records, store old client data, review license/AMC updates, and edit account profiles.</p>
        </div>

        <div class="flex gap-2 flex-wrap">
            <button class="btn btn-primary text-sm flex align-center gap-2" onclick="window.openModal('import-client-modal');">
                <i data-lucide="file-up" style="width: 16px; height: 16px;"></i>
                <span>Bulk Import Clients (Excel/CSV)</span>
            </button>
            <a href="index.php?page=clients&action=export_client_directory" class="btn btn-secondary text-sm flex align-center gap-2">
                <i data-lucide="download" style="width: 16px; height: 16px;"></i>
                <span>Export Client Directory</span>
            </a>
            <button class="btn btn-secondary text-sm" onclick="window.print();">
                <i data-lucide="printer" style="width: 16px; height: 16px;"></i>
                <span>Print Directory</span>
            </button>
        </div>
    </div>

    <!-- Workspace Tabs Navigation -->
    <div class="flex align-center gap-3 border-bottom mb-6" style="border-bottom: 2px solid var(--border-color);">
        <a href="index.php?page=clients&tab=directory" class="px-4 py-3 text-sm font-bold flex align-center gap-2" style="text-decoration: none; border-bottom: 3px solid <?php echo ($active_tab === 'directory') ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo ($active_tab === 'directory') ? 'var(--primary)' : 'var(--text-muted)'; ?>;">
            <i data-lucide="database" style="width: 18px; height: 18px;"></i>
            <span>Client Directory Database (Old Clients)</span>
            <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);"><?php echo number_format($dir_total_count); ?></span>
        </a>

        <a href="index.php?page=clients&tab=crm" class="px-4 py-3 text-sm font-bold flex align-center gap-2" style="text-decoration: none; border-bottom: 3px solid <?php echo ($active_tab === 'crm') ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo ($active_tab === 'crm') ? 'var(--primary)' : 'var(--text-muted)'; ?>;">
            <i data-lucide="building-2" style="width: 18px; height: 18px;"></i>
            <span>Active CRM Client Accounts</span>
            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);"><?php echo number_format($crm_total_count); ?></span>
        </a>
    </div>

    <?php if ($active_tab === 'directory'): ?>
        <!-- ==================================================================== -->
        <!-- TAB 1: CLIENT DIRECTORY DATABASE (client_directory TABLE)             -->
        <!-- ==================================================================== -->

        <!-- KPI Metrics Row (Modern 4-Column Card Grid) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
            <!-- Card 1: Total Client Records -->
            <div class="card" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 16px; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="width: 52px; height: 52px; border-radius: 14px; background-color: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="users" style="width: 24px; height: 24px;"></i>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted);">Total Client Records</span>
                    <span style="font-size: 1.5rem; font-weight: 800; color: var(--text-main); font-family: var(--font-heading); margin-top: 2px;"><?php echo number_format($dir_total_count); ?></span>
                </div>
            </div>

            <!-- Card 2: Running Party Accounts -->
            <div class="card" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 16px; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="width: 52px; height: 52px; border-radius: 14px; background-color: rgba(16, 185, 129, 0.12); color: #10b981; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="check-circle-2" style="width: 24px; height: 24px;"></i>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted);">Running Party Accounts</span>
                    <span style="font-size: 1.5rem; font-weight: 800; color: #10b981; font-family: var(--font-heading); margin-top: 2px;"><?php echo number_format($dir_running_count); ?></span>
                </div>
            </div>

            <!-- Card 3: Total Directory Value -->
            <div class="card" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 16px; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="width: 52px; height: 52px; border-radius: 14px; background-color: rgba(139, 92, 246, 0.12); color: #8b5cf6; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="indian-rupee" style="width: 24px; height: 24px;"></i>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted);">Total Directory Value</span>
                    <span style="font-size: 1.4rem; font-weight: 800; color: #8b5cf6; font-family: monospace; margin-top: 2px;">₹<?php echo number_format($dir_total_val); ?></span>
                </div>
            </div>

            <!-- Card 4: Software Trade Types -->
            <div class="card" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 16px; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="width: 52px; height: 52px; border-radius: 14px; background-color: rgba(245, 158, 11, 0.12); color: #f59e0b; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="layers" style="width: 24px; height: 24px;"></i>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted);">Software Trade Types</span>
                    <span style="font-size: 1.5rem; font-weight: 800; color: #f59e0b; font-family: var(--font-heading); margin-top: 2px;"><?php echo count($dir_trade_types); ?></span>
                </div>
            </div>
        </div>

        <!-- Search & Filter Panel for Directory — Modern Premium Redesign -->
        <div class="card mb-6" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 18px; padding: 1.25rem 1.5rem; box-shadow: 0 4px 16px rgba(0,0,0,0.03);">
            <form action="index.php" method="GET" class="flex flex-col gap-4">
                <input type="hidden" name="page" value="clients">
                <input type="hidden" name="tab" value="directory">

                <div class="flex justify-between align-center pb-3" style="border-bottom: 1px solid var(--border-color);">
                    <div class="flex align-center gap-2">
                        <div style="background: var(--primary-light); padding: 0.4rem; border-radius: 8px; display:flex; align-items:center; justify-content:center;">
                            <i data-lucide="filter" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        </div>
                        <h3 class="m-0 text-sm font-extrabold" style="font-family: var(--font-heading); color: var(--text-main);">Filter Client Directory Data</h3>
                    </div>
                    <?php if (!empty($search_query) || !empty($status_filter) || !empty($category_filter) || !empty($trade_filter)): ?>
                        <a href="index.php?page=clients&tab=directory" class="btn text-xs flex align-center gap-1" style="background: rgba(239, 68, 68, 0.08); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 8px; font-weight: 600; padding: 0.35rem 0.85rem; transition: all 0.2s;" onmouseover="this.style.background='rgba(239, 68, 68, 0.16)'" onmouseout="this.style.background='rgba(239, 68, 68, 0.08)'">
                            <i data-lucide="rotate-ccw" style="width: 12px; height: 12px;"></i>
                            <span>Clear Search Filters</span>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="grid" style="grid-template-columns: 2.2fr 1fr 1fr 1fr 1.1fr; gap: 1rem; align-items: end;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-muted mb-1" style="letter-spacing: 0.02em; display: block;">Search Client Directory</label>
                        <div style="display: flex; align-items: center; width: 100%; height: 42px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-app); overflow: hidden; transition: border-color 0.2s, box-shadow 0.2s;" onfocusin="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.15)';" onfocusout="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                            <div style="padding-left: 14px; padding-right: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--primary);">
                                <i data-lucide="search" style="width: 16px; height: 16px;"></i>
                            </div>
                            <input type="text" name="search" placeholder="Party Name, Customer ID, Mobile, Email, City..." value="<?php echo htmlspecialchars($search_query); ?>" style="border: none !important; outline: none !important; background: transparent !important; height: 100%; width: 100%; padding: 0 14px 0 0 !important; color: var(--text-main); font-size: 0.85rem; box-shadow: none !important;">
                        </div>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-muted mb-1" style="letter-spacing: 0.02em; display: block;">Client Category</label>
                        <select name="category" class="form-control form-control-focus text-sm" style="height: 42px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-app); color: var(--text-main); font-size: 0.85rem;">
                            <option value="">All Categories</option>
                            <option value="Category A" <?php echo (strcasecmp($category_filter, 'Category A') === 0) ? 'selected' : ''; ?>>Category A</option>
                            <option value="Category B" <?php echo (strcasecmp($category_filter, 'Category B') === 0) ? 'selected' : ''; ?>>Category B</option>
                            <option value="Category C" <?php echo (strcasecmp($category_filter, 'Category C') === 0) ? 'selected' : ''; ?>>Category C</option>
                            <option value="Category D" <?php echo (strcasecmp($category_filter, 'Category D') === 0) ? 'selected' : ''; ?>>Category D</option>
                            <?php foreach ($dir_categories as $cat): ?>
                                <?php if (!in_array($cat, ['Category A', 'Category B', 'Category C', 'Category D'])): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo (strcasecmp($category_filter, $cat) === 0) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-muted mb-1" style="letter-spacing: 0.02em; display: block;">Party Status</label>
                        <select name="status" class="form-control form-control-focus text-sm" style="height: 42px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-app); color: var(--text-main); font-size: 0.85rem;">
                            <option value="">All Statuses</option>
                            <option value="Running" <?php echo (strcasecmp($status_filter, 'Running') === 0) ? 'selected' : ''; ?>>Running</option>
                            <option value="Expired" <?php echo (strcasecmp($status_filter, 'Expired') === 0) ? 'selected' : ''; ?>>Expired</option>
                            <option value="Deactive" <?php echo (strcasecmp($status_filter, 'Deactive') === 0) ? 'selected' : ''; ?>>Deactive</option>
                            <option value="Suspended" <?php echo (strcasecmp($status_filter, 'Suspended') === 0) ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-muted mb-1" style="letter-spacing: 0.02em; display: block;">Software Trade</label>
                        <select name="trade" class="form-control form-control-focus text-sm" style="height: 42px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-app); color: var(--text-main); font-size: 0.85rem;">
                            <option value="">All Trade Categories</option>
                            <?php foreach ($dir_trade_types as $tr): ?>
                                <option value="<?php echo htmlspecialchars($tr); ?>" <?php echo ($trade_filter === $tr) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tr); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold mb-1" style="visibility: hidden; display: block;">&nbsp;</label>
                        <button type="submit" class="btn btn-primary font-bold text-sm" style="width: 100%; height: 42px; border-radius: 10px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark, #1e40af) 100%); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); display: flex; align-items: center; justify-content: center; gap: 8px;">
                            <i data-lucide="search" style="width: 15px; height: 15px;"></i>
                            <span>Search Records</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Directory Data Table Card -->
        <div class="card p-0" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-lg); overflow: hidden;">
            <div class="p-4 flex justify-between align-center flex-wrap gap-3" style="border-bottom: 1px solid var(--border-color); background-color: var(--border-card);">
                <div class="flex align-center gap-2">
                    <span class="text-sm font-bold text-main">Matching Directory Records:</span>
                    <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-weight: 700; font-size: 0.8rem;">
                        <?php echo number_format($dir_matching_count); ?> Records Found
                    </span>
                </div>

                <div class="flex align-center gap-3 text-xs flex-wrap">
                    <!-- Column Header Customizer / Toggle Option Button -->
                    <button type="button" class="btn btn-secondary text-xs flex align-center gap-1" style="padding: 0.35rem 0.75rem; border-color: var(--primary-light);" onclick="window.openModal('manage-dir-columns-modal');">
                        <i data-lucide="sliders-horizontal" style="width: 14px; height: 14px; color: var(--primary);"></i>
                        <span class="font-semibold text-primary">Select Columns / Toggle Headers</span>
                    </button>

                    <div class="flex align-center gap-2">
                        <span class="text-muted">Rows Per Page:</span>
                        <a href="<?php echo getClientsPageUrl('directory', 1, 25); ?>" class="btn btn-secondary text-xs <?php echo ($limit == 25) ? 'active' : ''; ?>" style="padding: 0.2rem 0.5rem;">25</a>
                        <a href="<?php echo getClientsPageUrl('directory', 1, 50); ?>" class="btn btn-secondary text-xs <?php echo ($limit == 50) ? 'active' : ''; ?>" style="padding: 0.2rem 0.5rem;">50</a>
                        <a href="<?php echo getClientsPageUrl('directory', 1, 'all'); ?>" class="btn btn-secondary text-xs <?php echo ($limit === 'all') ? 'active' : ''; ?>" style="padding: 0.2rem 0.5rem;">All</a>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table" style="font-size: 0.825rem; white-space: nowrap;" id="directory-main-table">
                    <thead>
                        <tr style="text-align: left; background-color: var(--bg-app);">
                            <th class="col-dir-sno" style="padding: 0.85rem 0.75rem;">S.No</th>
                            <th class="col-dir-sw-type">S/W Type</th>
                            <th class="col-dir-customer-id">Customer ID</th>
                            <th class="col-dir-category">Category</th>
                            <th class="col-dir-subpartner-code">SubPartner Code</th>
                            <th class="col-dir-subpartner-name">SubPartner Name</th>
                            <th class="col-dir-party-name">Party Name</th>
                            <th class="col-dir-company-using">Company Using</th>
                            <th class="col-dir-address">Address</th>
                            <th class="col-dir-mobile">Mobile</th>
                            <th class="col-dir-email">Email ID</th>
                            <th class="col-dir-user-type">User Type</th>
                            <th class="col-dir-software-type">Software Type</th>
                            <th class="col-dir-no-of-users">No. of Users</th>
                            <th class="col-dir-contact-person">Contact Person</th>
                            <th class="col-dir-due-on">Due On</th>
                            <th class="col-dir-act-on">Act On</th>
                            <th class="col-dir-days">Days</th>
                            <th class="col-dir-party-status">Party Status</th>
                            <th class="col-dir-city">City</th>
                            <th class="col-dir-transferred">Transferred</th>
                            <th class="col-dir-online-zip">Online Zip</th>
                            <th class="col-dir-state">State</th>
                            <th class="col-dir-home-user">Home User</th>
                            <th class="col-dir-software-trade">Software Trade</th>
                            <th class="col-dir-version">Version</th>
                            <th class="col-dir-total-amount">Total Amount</th>
                            <th class="col-dir-software-hitdate">Software HitDate</th>
                            <th class="col-dir-wallet-id">Wallet ID</th>
                            <th class="col-dir-actions" style="text-align: right; padding-right: 1.25rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dir_records)): ?>
                            <tr>
                                <td colspan="30" class="text-center text-muted py-8">
                                    <i data-lucide="database" style="width: 40px; height: 40px; margin: 0 auto 0.75rem auto; color: var(--text-muted);"></i>
                                    <p class="text-sm font-semibold mb-1">No client directory records found.</p>
                                    <p class="text-xs text-muted mb-3">Click below to bulk import your Excel or CSV file with old client details.</p>
                                    <button class="btn btn-primary text-xs" onclick="window.openModal('import-client-modal');">
                                        <i data-lucide="file-up" style="width: 14px; height: 14px;"></i>
                                        <span>Upload Old Client Excel Sheet</span>
                                    </button>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dir_records as $idx => $r): 
                                $perPage = is_numeric($limit) ? intval($limit) : 25;
                                $sn = ($limit === 'all') ? ($idx + 1) : (($page_num - 1) * $perPage + $idx + 1);
                                
                                if ($pdo && !empty($r['email'])) {
                                    $uStmt = $pdo->prepare("SELECT id as user_id, status as user_status, permissions FROM users WHERE email = ? LIMIT 1");
                                    $uStmt->execute([$r['email']]);
                                    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($uRow) {
                                        $r['user_id'] = $uRow['user_id'];
                                        $r['user_status'] = $uRow['user_status'];
                                        $r['permissions'] = $uRow['permissions'];
                                    }
                                }
                                $rJson = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                                
                                $catVal = $r['category'] ?? 'Category A';
                                $catBadgeStyle = '--badge-bg: var(--primary-light); --badge-color: var(--primary);';
                                if (strcasecmp($catVal, 'Category A') === 0 || strcasecmp($catVal, 'A') === 0) {
                                    $catBadgeStyle = '--badge-bg: rgba(16,185,129,0.12); --badge-color: #10b981;';
                                } elseif (strcasecmp($catVal, 'Category B') === 0 || strcasecmp($catVal, 'B') === 0) {
                                    $catBadgeStyle = '--badge-bg: rgba(59,130,246,0.12); --badge-color: #3b82f6;';
                                } elseif (strcasecmp($catVal, 'Category C') === 0 || strcasecmp($catVal, 'C') === 0) {
                                    $catBadgeStyle = '--badge-bg: rgba(245,158,11,0.12); --badge-color: #f59e0b;';
                                } elseif (strcasecmp($catVal, 'Category D') === 0 || strcasecmp($catVal, 'D') === 0) {
                                    $catBadgeStyle = '--badge-bg: rgba(139,92,246,0.12); --badge-color: #8b5cf6;';
                                }
                            ?>
                                <tr>
                                    <td class="col-dir-sno font-mono text-xs font-bold text-center" style="padding: 0.75rem; color: var(--text-main);"><?php echo $sn; ?></td>
                                    <td class="col-dir-sw-type"><span class="badge text-xs" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);"><?php echo htmlspecialchars($r['sw_type'] ?? 'Marg'); ?></span></td>
                                    <td class="col-dir-customer-id"><span class="font-bold text-primary font-mono"><?php echo htmlspecialchars($r['customer_id']); ?></span></td>
                                    <td class="col-dir-category"><span class="badge text-xs font-bold" style="<?php echo $catBadgeStyle; ?>"><?php echo htmlspecialchars($catVal); ?></span></td>
                                    <td class="col-dir-subpartner-code text-muted"><?php echo htmlspecialchars($r['subpartner_code'] ?? '-'); ?></td>
                                    <td class="col-dir-subpartner-name text-muted"><?php echo htmlspecialchars($r['subpartner_name'] ?? '-'); ?></td>
                                    <td class="col-dir-party-name">
                                        <strong style="color: var(--text-main);"><?php echo htmlspecialchars($r['party_name']); ?></strong>
                                        <?php if (!empty($r['user_id'])): ?>
                                            <span class="badge" style="--badge-bg: rgba(16,185,129,0.1); --badge-color: #10b981; font-size: 10px; margin-left: 4px;">Login Account</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-dir-company-using"><?php echo htmlspecialchars($r['company_using'] ?? '-'); ?></td>
                                    <td class="col-dir-address text-xs text-muted" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($r['address'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($r['address'] ?? '-'); ?>
                                    </td>
                                    <td class="col-dir-mobile font-mono text-xs"><a href="tel:<?php echo $r['mobile']; ?>" class="text-main hover-primary"><?php echo htmlspecialchars($r['mobile'] ?? '-'); ?></a></td>
                                    <td class="col-dir-email font-mono text-xs"><?php echo htmlspecialchars($r['email'] ?? '-'); ?></td>
                                    <td class="col-dir-user-type"><span class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-main);"><?php echo htmlspecialchars($r['user_type'] ?? 'Single'); ?></span></td>
                                    <td class="col-dir-software-type"><span class="badge text-xs" style="--badge-bg: var(--accent-light); --badge-color: var(--accent);"><?php echo htmlspecialchars($r['software_type'] ?? '-'); ?></span></td>
                                    <td class="col-dir-no-of-users font-mono text-center"><?php echo htmlspecialchars($r['no_of_users']); ?></td>
                                    <td class="col-dir-contact-person"><span class="font-semibold"><?php echo htmlspecialchars($r['contact_person'] ?? '-'); ?></span></td>
                                    <td class="col-dir-due-on font-mono text-xs text-muted"><?php echo htmlspecialchars($r['due_on'] ?? '-'); ?></td>
                                    <td class="col-dir-act-on font-mono text-xs text-muted"><?php echo htmlspecialchars($r['act_on'] ?? '-'); ?></td>
                                    <td class="col-dir-days font-mono text-xs"><?php echo htmlspecialchars($r['days']); ?></td>
                                    <td class="col-dir-party-status">
                                        <?php 
                                            $st = strtolower($r['party_status'] ?? '');
                                            if ($st === 'running' || $st === 'active') echo '<span class="badge badge-success text-xs">Running</span>';
                                            elseif ($st === 'expired') echo '<span class="badge badge-danger text-xs">Expired</span>';
                                            elseif ($st === 'deactive' || $st === 'deactivated' || $st === 'inactive') echo '<span class="badge badge-danger text-xs">Deactive</span>';
                                            else echo '<span class="badge text-xs" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">' . htmlspecialchars($r['party_status']) . '</span>';
                                        ?>
                                    </td>
                                    <td class="col-dir-city"><?php echo htmlspecialchars($r['city'] ?? '-'); ?></td>
                                    <td class="col-dir-transferred"><?php echo htmlspecialchars($r['transferred_party'] ?? 'No'); ?></td>
                                    <td class="col-dir-online-zip font-mono text-xs"><?php echo htmlspecialchars($r['online_zip_code'] ?? '-'); ?></td>
                                    <td class="col-dir-state"><?php echo htmlspecialchars($r['state'] ?? '-'); ?></td>
                                    <td class="col-dir-home-user"><?php echo htmlspecialchars($r['home_user'] ?? 'No'); ?></td>
                                    <td class="col-dir-software-trade"><span class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-main);"><?php echo htmlspecialchars($r['software_trade'] ?? '-'); ?></span></td>
                                    <td class="col-dir-version font-mono text-xs"><?php echo htmlspecialchars($r['version'] ?? '-'); ?></td>
                                    <td class="col-dir-total-amount font-extrabold text-success font-mono">₹<?php echo number_format($r['total_amount'], 2); ?></td>
                                    <td class="col-dir-software-hitdate font-mono text-xs text-muted"><?php echo htmlspecialchars($r['software_hit_date'] ?? '-'); ?></td>
                                    <td class="col-dir-wallet-id font-mono text-xs text-muted"><?php echo htmlspecialchars($r['wallet_id'] ?? '-'); ?></td>
                                    
                                    <!-- Actions Column: Credentials Modal, Folder & Edit Icons -->
                                    <td class="col-dir-actions" style="text-align: right; padding-right: 1.25rem;">
                                        <div style="display: flex; align-items: center; justify-content: flex-end; gap: 4px; margin-left: auto; width: 100%;">
                                            <!-- Folder Icon (Licence & AMC Window) -->
                                            <button type="button" class="btn-icon text-primary" title="View Licence & AMC Information Window" onclick='openLicenceAmcWindow(<?php echo $rJson; ?>)'>
                                                <i data-lucide="folder" style="width: 15px; height: 15px;"></i>
                                            </button>
                                            <!-- Edit Icon (Edit Client Record Modal) -->
                                            <button type="button" class="btn-icon text-secondary" title="Edit Client Details" onclick='openEditClientRecordModal(<?php echo $rJson; ?>)'>
                                                <i data-lucide="edit-3" style="width: 15px; height: 15px;"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar for Directory (Smart Truncated Pagination) -->
            <?php if ($limit !== 'all' && $dir_total_pages > 1): ?>
                <div class="p-4 flex justify-between align-center flex-wrap gap-2" style="border-top: 1px solid var(--border-color); background-color: var(--border-card);">
                    <span class="text-xs text-muted font-bold">Showing Directory Page <?php echo $page_num; ?> of <?php echo $dir_total_pages; ?></span>
                    <div class="flex gap-1 align-center flex-wrap">
                        <?php if ($page_num > 1): ?>
                            <a href="<?php echo getClientsPageUrl('directory', $page_num - 1, $limit); ?>" class="btn btn-secondary text-xs" style="padding: 0.35rem 0.65rem;">Previous</a>
                        <?php endif; ?>

                        <?php 
                            $prev_p = 0;
                            for ($i = 1; $i <= $dir_total_pages; $i++):
                                if ($i == 1 || $i == $dir_total_pages || ($i >= $page_num - 2 && $i <= $page_num + 2)):
                                    if ($prev_p > 0 && $i - $prev_p > 1):
                                        echo '<span class="text-xs text-muted px-1" style="line-height: 28px;">...</span>';
                                    endif;
                                    $prev_p = $i;
                        ?>
                            <a href="<?php echo getClientsPageUrl('directory', $i, $limit); ?>" class="btn btn-secondary text-xs <?php echo ($i == $page_num) ? 'active' : ''; ?>" style="<?php echo ($i == $page_num) ? 'background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 700;' : ''; ?> padding: 0.35rem 0.65rem;"><?php echo $i; ?></a>
                        <?php 
                                endif;
                            endfor; 
                        ?>

                        <?php if ($page_num < $dir_total_pages): ?>
                            <a href="<?php echo getClientsPageUrl('directory', $page_num + 1, $limit); ?>" class="btn btn-secondary text-xs" style="padding: 0.35rem 0.65rem;">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- ==================================================================== -->
        <!-- TAB 2: ACTIVE CRM CLIENT ACCOUNTS (leads TABLE)                      -->
        <!-- ==================================================================== -->

        <!-- KPI Summary Row for CRM Accounts (Modern 4-Column Card Grid) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
            <div class="card" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 16px; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="width: 52px; height: 52px; border-radius: 14px; background-color: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="building-2" style="width: 24px; height: 24px;"></i>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted);">Total CRM Clients</span>
                    <span style="font-size: 1.5rem; font-weight: 800; color: var(--text-main); font-family: var(--font-heading); margin-top: 2px;"><?php echo number_format($crm_total_count); ?></span>
                </div>
            </div>

            <div class="card" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 16px; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="width: 52px; height: 52px; border-radius: 14px; background-color: rgba(16, 185, 129, 0.12); color: #10b981; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="check-circle-2" style="width: 24px; height: 24px;"></i>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted);">Installed &amp; Active</span>
                    <span style="font-size: 1.5rem; font-weight: 800; color: #10b981; font-family: var(--font-heading); margin-top: 2px;"><?php echo number_format($crm_installed_count); ?></span>
                </div>
            </div>

            <div class="card" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 16px; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="width: 52px; height: 52px; border-radius: 14px; background-color: rgba(139, 92, 246, 0.12); color: #8b5cf6; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="indian-rupee" style="width: 24px; height: 24px;"></i>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted);">Portfolio Contract Value</span>
                    <span style="font-size: 1.4rem; font-weight: 800; color: #8b5cf6; font-family: monospace; margin-top: 2px;">₹<?php echo number_format($crm_portfolio_val / 100000, 2); ?>L</span>
                </div>
            </div>

            <div class="card" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 16px; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                <div style="width: 52px; height: 52px; border-radius: 14px; background-color: rgba(245, 158, 11, 0.12); color: #f59e0b; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="user-check" style="width: 24px; height: 24px;"></i>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted);">Account Reps</span>
                    <span style="font-size: 1.5rem; font-weight: 800; color: #f59e0b; font-family: var(--font-heading); margin-top: 2px;"><?php echo count($operators_list); ?></span>
                </div>
            </div>
        </div>

        <!-- CRM Clients Search Panel — Modern Premium Redesign -->
        <div class="card mb-6" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 18px; padding: 1.25rem 1.5rem; box-shadow: 0 4px 16px rgba(0,0,0,0.03);">
            <form action="index.php" method="GET" class="flex flex-col gap-4">
                <input type="hidden" name="page" value="clients">
                <input type="hidden" name="tab" value="crm">
                
                <div class="flex justify-between align-center pb-3" style="border-bottom: 1px solid var(--border-color);">
                    <div class="flex align-center gap-2">
                        <div style="background: var(--primary-light); padding: 0.4rem; border-radius: 8px; display:flex; align-items:center; justify-content:center;">
                            <i data-lucide="search-code" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        </div>
                        <h3 class="m-0 text-sm font-extrabold" style="font-family: var(--font-heading); color: var(--text-main);">Filter CRM Accounts</h3>
                    </div>
                    <?php if (!empty($search_query) || !empty($status_filter) || !empty($product_filter) || !empty($operator_filter)): ?>
                        <a href="index.php?page=clients&tab=crm" class="btn text-xs flex align-center gap-1" style="background: rgba(239, 68, 68, 0.08); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 8px; font-weight: 600; padding: 0.35rem 0.85rem; transition: all 0.2s;" onmouseover="this.style.background='rgba(239, 68, 68, 0.16)'" onmouseout="this.style.background='rgba(239, 68, 68, 0.08)'">
                            <i data-lucide="rotate-ccw" style="width: 12px; height: 12px;"></i>
                            <span>Clear All Filters</span>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="grid" style="grid-template-columns: 2.2fr 1fr 1fr 1fr 1.1fr; gap: 1rem; align-items: end;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-muted mb-1" style="letter-spacing: 0.02em; display: block;">Search Client Accounts</label>
                        <div style="display: flex; align-items: center; width: 100%; height: 42px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-app); overflow: hidden; transition: border-color 0.2s, box-shadow 0.2s;" onfocusin="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.15)';" onfocusout="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                            <div style="padding-left: 14px; padding-right: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--primary);">
                                <i data-lucide="search" style="width: 16px; height: 16px;"></i>
                            </div>
                            <input type="text" name="search" placeholder="Company, Phone, Email, GSTIN, City..." value="<?php echo htmlspecialchars($search_query); ?>" style="border: none !important; outline: none !important; background: transparent !important; height: 100%; width: 100%; padding: 0 14px 0 0 !important; color: var(--text-main); font-size: 0.85rem; box-shadow: none !important;">
                        </div>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-muted mb-1" style="letter-spacing: 0.02em; display: block;">Client Status</label>
                        <select name="status" class="form-control form-control-focus text-sm" style="height: 42px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-app); color: var(--text-main); font-size: 0.85rem;">
                            <option value="">All Statuses</option>
                            <option value="won" <?php echo ($status_filter === 'won' || $status_filter === 'closed_won') ? 'selected' : ''; ?>>Closed Won</option>
                            <option value="payment_received" <?php echo ($status_filter === 'payment_received') ? 'selected' : ''; ?>>Payment Received</option>
                            <option value="install_pending" <?php echo ($status_filter === 'install_pending') ? 'selected' : ''; ?>>Installation Pending</option>
                            <option value="install_completed" <?php echo ($status_filter === 'install_completed') ? 'selected' : ''; ?>>Installation Completed</option>
                            <option value="training_completed" <?php echo ($status_filter === 'training_completed') ? 'selected' : ''; ?>>Training Completed</option>
                            <option value="support" <?php echo ($status_filter === 'support') ? 'selected' : ''; ?>>Active Support</option>
                            <option value="renewal" <?php echo ($status_filter === 'renewal') ? 'selected' : ''; ?>>Renewal Due</option>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-muted mb-1" style="letter-spacing: 0.02em; display: block;">Software Product</label>
                        <select name="product" class="form-control form-control-focus text-sm" style="height: 42px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-app); color: var(--text-main); font-size: 0.85rem;">
                            <option value="">All Products</option>
                            <?php foreach ($products_options as $pOpt): ?>
                                <option value="<?php echo htmlspecialchars($pOpt); ?>" <?php echo (strcasecmp($product_filter, $pOpt) === 0) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pOpt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-muted mb-1" style="letter-spacing: 0.02em; display: block;">Account Representative</label>
                        <select name="operator" class="form-control form-control-focus text-sm" style="height: 42px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-app); color: var(--text-main); font-size: 0.85rem;">
                            <option value="">All Operators</option>
                            <?php foreach ($operators_list as $op): ?>
                                <option value="<?php echo htmlspecialchars($op); ?>" <?php echo ($operator_filter === $op) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($op); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold mb-1" style="visibility: hidden; display: block;">&nbsp;</label>
                        <button type="submit" class="btn btn-primary font-bold text-sm" style="width: 100%; height: 42px; border-radius: 10px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark, #1e40af) 100%); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); display: flex; align-items: center; justify-content: center; gap: 8px;">
                            <i data-lucide="search" style="width: 15px; height: 15px;"></i>
                            <span>Apply Filters</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- CRM Clients Table Card -->
        <div class="card p-0" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-lg); overflow: hidden;">
            <div class="p-4 flex justify-between align-center flex-wrap gap-3" style="border-bottom: 1px solid var(--border-color); background-color: var(--border-card);">
                <div class="flex align-center gap-2">
                    <span class="text-sm font-bold text-main">Matching CRM Client Accounts:</span>
                    <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-weight: 700; font-size: 0.8rem;">
                        <?php echo number_format($crm_matching_count); ?> Accounts
                    </span>
                </div>

                <div class="flex align-center gap-3 text-xs flex-wrap">
                    <div class="flex align-center gap-2">
                        <span class="text-muted">Rows Per Page:</span>
                        <a href="<?php echo getClientsPageUrl('crm', 1, 25); ?>" class="btn btn-secondary text-xs <?php echo ($limit == 25) ? 'active' : ''; ?>" style="padding: 0.2rem 0.5rem;">25</a>
                        <a href="<?php echo getClientsPageUrl('crm', 1, 50); ?>" class="btn btn-secondary text-xs <?php echo ($limit == 50) ? 'active' : ''; ?>" style="padding: 0.2rem 0.5rem;">50</a>
                        <a href="<?php echo getClientsPageUrl('crm', 1, 'all'); ?>" class="btn btn-secondary text-xs <?php echo ($limit === 'all') ? 'active' : ''; ?>" style="padding: 0.2rem 0.5rem;">All</a>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table" style="font-size: 0.85rem;">
                    <thead>
                        <tr style="text-align: left; background-color: var(--bg-app);">
                            <th style="padding: 0.85rem 1rem;">Client ID</th>
                            <th>Company Name</th>
                            <th>Contact Person</th>
                            <th>Reg Mobile No</th>
                            <th>Reg Email</th>
                            <th>Registered Address</th>
                            <th>GSTN No</th>
                            <th>Edition / Product</th>
                            <th>Account Representative</th>
                            <th>Contract Value</th>
                            <th>Status</th>
                            <th style="text-align: right; padding-right: 1.25rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($crm_records)): ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted py-8">
                                    <i data-lucide="building" style="width: 40px; height: 40px; margin: 0 auto 0.75rem auto; color: var(--text-muted);"></i>
                                    <p class="text-sm font-semibold mb-1">No active CRM client records match your filter criteria.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($crm_records as $client): ?>
                                <tr>
                                    <td style="padding: 0.85rem 1rem;">
                                        <a href="index.php?page=lead_details&id=<?php echo $client['id']; ?>" class="font-bold text-primary hover-underline text-xs">
                                            <?php echo $client['id']; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="index.php?page=lead_details&id=<?php echo $client['id']; ?>" class="font-bold text-main hover-primary text-sm">
                                            <?php echo htmlspecialchars($client['company']); ?>
                                        </a>
                                    </td>
                                    <td><span class="font-semibold text-main"><?php echo htmlspecialchars(!empty($client['contact_person']) ? $client['contact_person'] : $client['name']); ?></span></td>
                                    <td>
                                        <a href="tel:<?php echo $client['phone']; ?>" class="text-xs text-muted hover-primary font-mono flex align-center gap-1">
                                            <i data-lucide="phone" style="width: 11px; height: 11px;"></i>
                                            <?php echo htmlspecialchars($client['phone']); ?>
                                        </a>
                                    </td>
                                    <td class="font-mono text-xs"><?php echo htmlspecialchars($client['email'] ?? 'NA'); ?></td>
                                    <td class="text-xs text-muted" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($client['address'] ?? 'NA'); ?></td>
                                    <td class="font-mono text-xs font-semibold"><?php echo htmlspecialchars($client['gst'] ?? 'NA'); ?></td>
                                    <td>
                                        <span class="badge text-xs" style="--badge-bg: var(--accent-light); --badge-color: var(--accent); font-weight: 600;">
                                            <?php echo htmlspecialchars(!empty($client['enq_for']) ? $client['enq_for'] : (!empty($client['products']) ? $client['products'] : 'Marg ERP Pro')); ?>
                                        </span>
                                    </td>
                                    <td><span class="text-xs font-semibold"><?php echo htmlspecialchars(!empty($client['assigned_to']) ? $client['assigned_to'] : 'Unassigned'); ?></span></td>
                                    <td><span class="text-sm font-extrabold text-success" style="font-family: var(--font-heading);">₹<?php echo number_format($client['budget'], 0); ?></span></td>
                                    <td><?php echo getStatusBadge($client['status']); ?></td>
                                    <td style="text-align: right; padding-right: 1.25rem;">
                                        <div class="flex align-center justify-end gap-1">
                                            <a href="index.php?page=lead_details&id=<?php echo $client['id']; ?>" class="btn-icon" title="View Profile">
                                                <i data-lucide="eye" style="width: 15px; height: 15px;"></i>
                                            </a>
                                            <a href="index.php?page=lead_form&action=edit&id=<?php echo $client['id']; ?>" class="btn-icon" title="Edit Profile">
                                                <i data-lucide="edit-3" style="width: 15px; height: 15px;"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Controls Bar (Smart Truncated Pagination) -->
            <?php if ($limit !== 'all' && $crm_total_pages > 1): ?>
                <div class="p-4 flex justify-between align-center flex-wrap gap-2" style="border-top: 1px solid var(--border-color); background-color: var(--border-card);">
                    <span class="text-xs text-muted font-bold">Showing Page <?php echo $page_num; ?> of <?php echo $crm_total_pages; ?></span>
                    <div class="flex gap-1 align-center flex-wrap">
                        <?php if ($page_num > 1): ?>
                            <a href="<?php echo getClientsPageUrl('crm', $page_num - 1, $limit); ?>" class="btn btn-secondary text-xs" style="padding: 0.35rem 0.65rem;">Previous</a>
                        <?php endif; ?>

                        <?php 
                            $prev_p = 0;
                            for ($i = 1; $i <= $crm_total_pages; $i++):
                                if ($i == 1 || $i == $crm_total_pages || ($i >= $page_num - 2 && $i <= $page_num + 2)):
                                    if ($prev_p > 0 && $i - $prev_p > 1):
                                        echo '<span class="text-xs text-muted px-1" style="line-height: 28px;">...</span>';
                                    endif;
                                    $prev_p = $i;
                        ?>
                            <a href="<?php echo getClientsPageUrl('crm', $i, $limit); ?>" class="btn btn-secondary text-xs <?php echo ($i == $page_num) ? 'active' : ''; ?>" style="<?php echo ($i == $page_num) ? 'background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 700;' : ''; ?> padding: 0.35rem 0.65rem;"><?php echo $i; ?></a>
                        <?php 
                                endif;
                            endfor; 
                        ?>

                        <?php if ($page_num < $crm_total_pages): ?>
                            <a href="<?php echo getClientsPageUrl('crm', $page_num + 1, $limit); ?>" class="btn btn-secondary text-xs" style="padding: 0.35rem 0.65rem;">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<!-- Modal 1: Bulk Import Client Directory (Excel / CSV) -->
<div id="import-client-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 650px; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color);">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 0.5rem; border-radius: 8px;">
                    <i data-lucide="file-up" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.2rem; font-weight: 700; color: var(--text-main);">
                        Bulk Import Old Client Directory
                    </h3>
                    <span class="text-xs text-muted">Upload your client database Excel sheet (.xlsx) or CSV file to populate the client_directory table.</span>
                </div>
            </div>
            <button class="btn-icon" onclick="window.closeModal('import-client-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form action="index.php?page=clients&tab=directory" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_client_directory">
            
            <div class="modal-body p-6 flex flex-col gap-5">
                <div class="p-4 flex align-center justify-between gap-3" style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: 10px;">
                    <div>
                        <span class="text-xs font-bold text-main block mb-1">Need sample format spreadsheet?</span>
                        <span class="text-xs text-muted">Download template with all 28 pre-formatted Client Directory headers.</span>
                    </div>
                    <a href="index.php?page=clients&action=download_client_template" class="btn btn-secondary text-xs flex align-center gap-1" style="flex-shrink: 0;">
                        <i data-lucide="download" style="width: 14px; height: 14px; color: var(--primary);"></i>
                        <span>Download Template</span>
                    </a>
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-bold">Select Client Directory File (.xlsx, .csv)</label>
                    <div style="border: 2px dashed var(--border-color); padding: 2rem; border-radius: 12px; text-align: center; background-color: var(--bg-app); cursor: pointer;" onclick="document.getElementById('client-file-input').click();">
                        <i data-lucide="upload-cloud" style="width: 42px; height: 42px; color: var(--primary); margin-bottom: 0.5rem;"></i>
                        <p class="text-sm font-semibold mb-1 text-main" id="file-name-display">Click to browse or drag & drop Excel / CSV file</p>
                        <p class="text-xs text-muted m-0">Supports .xlsx, .csv files with up to 10,000+ client rows</p>
                        <input type="file" id="client-file-input" name="client_file" accept=".csv, .xlsx, .xls" required style="display: none;" onchange="document.getElementById('file-name-display').innerText = this.files[0] ? 'Selected File: ' + this.files[0].name : 'Click to browse or drag & drop Excel / CSV file';">
                    </div>
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-bold mb-2 block">Duplicate Customer ID Handling</label>
                    <div class="grid grid-2 gap-3">
                        <label class="flex align-center gap-2 p-3 card pointer text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                            <input type="radio" name="duplicate_option" value="update" checked style="accent-color: var(--primary);">
                            <div>
                                <strong class="block text-main">Update Existing Records</strong>
                                <span class="text-muted">Overwrite fields if Customer ID matches.</span>
                            </div>
                        </label>

                        <label class="flex align-center gap-2 p-3 card pointer text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                            <input type="radio" name="duplicate_option" value="skip" style="accent-color: var(--primary);">
                            <div>
                                <strong class="block text-main">Skip Duplicates</strong>
                                <span class="text-muted">Keep existing records without changes.</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-footer p-4 flex justify-end gap-2" style="background-color: var(--border-card); border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('import-client-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm flex align-center gap-2">
                    <i data-lucide="upload" style="width: 16px; height: 16px;"></i>
                    <span>Upload & Import Records</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Select Columns / Toggle Table Headers for Client Directory (⚙️) -->
<div id="manage-dir-columns-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 780px; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color);">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 0.5rem; border-radius: 8px;">
                    <i data-lucide="sliders-horizontal" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.2rem; font-weight: 700; color: var(--text-main);">
                        Select Columns / Toggle Table Headers
                    </h3>
                    <span class="text-xs text-muted">Check or uncheck headers to customize which of the 28 columns display in your Client Directory database.</span>
                </div>
            </div>
            <button class="btn-icon" onclick="window.closeModal('manage-dir-columns-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <div class="modal-body p-6 flex flex-col gap-4">
            <div class="flex justify-between align-center border-bottom pb-3 mb-2" style="border-bottom: 1px solid var(--border-color);">
                <span class="text-xs font-bold text-main">Check headers to show:</span>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-secondary text-xs" style="padding: 0.25rem 0.5rem;" onclick="selectAllDirColumns(true)">Select All</button>
                    <button type="button" class="btn btn-secondary text-xs" style="padding: 0.25rem 0.5rem;" onclick="selectAllDirColumns(false)">Deselect All</button>
                    <button type="button" class="btn btn-secondary text-xs text-danger" style="padding: 0.25rem 0.5rem;" onclick="resetDefaultDirColumns()">Reset Defaults</button>
                </div>
            </div>

            <div class="grid grid-3 gap-2" style="max-height: 380px; overflow-y: auto; padding-right: 0.5rem;">
                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-sno" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">1. S.No</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-sw-type" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">2. S/W Type</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-customer-id" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">3. CUSTOMER ID</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-category" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">4. Category (Category A, B, C...)</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-subpartner-code" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">4. SubPartner Code</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-subpartner-name" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">5. SubPartner Name</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-party-name" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">6. Party Name</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-company-using" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">7. Company Using</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-address" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">8. Address</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-mobile" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">9. Mobile</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-email" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">10. Email ID</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-user-type" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">11. User (Single/Multi)</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-software-type" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">12. Type (Software Type)</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-no-of-users" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">13. No. of Users</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-contact-person" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">14. Contact Person</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-due-on" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">15. Due On</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-act-on" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">16. Act On</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-days" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">17. Days</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-party-status" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">18. Party Status</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-city" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">19. City</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-transferred" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">20. Transferred Party</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-online-zip" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">21. Online Zip Code</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-state" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">22. State</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-home-user" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">23. Home User</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-software-trade" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">24. Software Trade</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-version" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">25. Version</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-total-amount" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">26. Total Amount</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-software-hitdate" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">27. Software HitDate</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-wallet-id" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">28. Wallet ID</span>
                </label>

                <label class="flex align-center gap-2 p-2 pointer card text-xs" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                    <input type="checkbox" class="dir-col-checkbox" value="col-dir-actions" style="accent-color: var(--primary); scale: 1.1;">
                    <span class="text-main">29. Actions (Folder/Edit)</span>
                </label>
            </div>

            <div class="flex justify-between align-center pt-4" style="border-top: 1px solid var(--border-color); margin-top: 0.5rem;">
                <button type="button" class="btn btn-secondary text-xs text-danger" onclick="resetDefaultDirColumns()">Reset to Default Headers</button>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('manage-dir-columns-modal')">Cancel</button>
                    <button type="button" class="btn btn-primary text-sm" onclick="applyDirColumnPreferences()">Save & Apply Visibility</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal 3: Client Licence & AMC Information Window — Modern Premium Redesign -->
<div id="licence-amc-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 1200px; width: 96%; max-height: 90vh; display: flex; flex-direction: column; background: var(--bg-card); color: var(--text-main); border-radius: 20px; border: 1px solid var(--border-color); box-shadow: 0 32px 64px -12px rgba(0,0,0,0.6); overflow: hidden;">

        <!-- ── HEADER (Fixed Top) ── -->
        <div style="flex-shrink: 0; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark, #1e40af) 100%); padding: 1rem 1.5rem;" class="flex align-center justify-between">
            <div class="flex align-center gap-3">
                <div style="background: rgba(255,255,255,0.18); backdrop-filter: blur(8px); padding: 0.55rem; border-radius: 12px; display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="folder-open" style="width:20px; height:20px; color:#fff;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 800; color: #fff; letter-spacing: -0.01em;">
                        Client Licence &amp; AMC Workspace
                    </h3>
                    <span style="font-size: 0.72rem; color: rgba(255,255,255,0.75);">Review license details &amp; execute AMC fee updates</span>
                </div>
            </div>
            <button type="button" onclick="window.closeModal('licence-amc-modal')" style="background: rgba(255,255,255,0.15); border: none; border-radius: 10px; width: 34px; height: 34px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.28)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                <i data-lucide="x" style="width:18px; height:18px; color:#fff;"></i>
            </button>
        </div>

        <form action="index.php?page=clients&tab=directory" method="POST" style="display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden;">
            <input type="hidden" name="action" value="update_client_amc">
            <input type="hidden" id="amc_client_id" name="amc_client_id" value="">

            <!-- ── SEARCH BAR STRIP (Fixed Sub-header) ── -->
            <div style="flex-shrink: 0; background: var(--bg-app); border-bottom: 1px solid var(--border-color); padding: 0.75rem 1.5rem;">
                <div class="flex align-center gap-3 flex-wrap">
                    <!-- Product Type Pills -->
                    <div style="display:flex; gap: 0.4rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 10px; padding: 0.25rem;">
                        <label style="display:flex; align-items:center; gap:5px; padding: 0.25rem 0.65rem; border-radius: 7px; cursor:pointer; font-size: 0.72rem; font-weight: 700; background: var(--primary); color: #fff; transition: all 0.2s;" id="lbl_marg_erp" onclick="highlightProductPill('marg_erp')">
                            <input type="radio" name="sw_product_type" value="MARG ERP" checked style="display:none;" id="radio_marg_erp">
                            <i data-lucide="cpu" style="width:13px; height:13px;"></i> MARG ERP
                        </label>
                        <label style="display:flex; align-items:center; gap:5px; padding: 0.25rem 0.65rem; border-radius: 7px; cursor:pointer; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); transition: all 0.2s;" id="lbl_marg_books" onclick="highlightProductPill('marg_books')">
                            <input type="radio" name="sw_product_type" value="Marg Books" style="display:none;" id="radio_marg_books">
                            <i data-lucide="book-open" style="width:13px; height:13px;"></i> Marg Books
                        </label>
                    </div>

                    <div style="width: 1px; height: 28px; background: var(--border-color); margin: 0 0.2rem;"></div>

                    <!-- Search Inputs -->
                    <div style="position:relative; display:flex; align-items:center;">
                        <i data-lucide="hash" style="position:absolute; left:10px; width:13px; height:13px; color: var(--text-muted);"></i>
                        <input type="text" id="win_licence_no_input" placeholder="Licence No" class="form-control form-control-focus font-mono" style="padding-left: 30px; width: 145px; font-size: 0.75rem; border-radius: 9px; height: 34px;">
                    </div>
                    <div style="position:relative; display:flex; align-items:center;">
                        <i data-lucide="phone" style="position:absolute; left:10px; width:13px; height:13px; color: var(--text-muted);"></i>
                        <input type="text" id="win_mobile_no_input" placeholder="Mobile No" class="form-control form-control-focus font-mono" style="padding-left: 30px; width: 145px; font-size: 0.75rem; border-radius: 9px; height: 34px;">
                    </div>
                    <div style="position:relative; display:flex; align-items:center; flex:1; min-width: 180px;">
                        <i data-lucide="building-2" style="position:absolute; left:10px; width:13px; height:13px; color: var(--text-muted);"></i>
                        <input type="text" id="win_firm_name_input" placeholder="Firm / Company Name" class="form-control form-control-focus" style="padding-left: 30px; width: 100%; font-size: 0.75rem; border-radius: 9px; height: 34px;">
                    </div>
                    <button type="button" onclick="performWinLicenceSearch()" class="btn btn-primary font-bold flex align-center gap-2" style="height: 34px; padding: 0 1.1rem; font-size: 0.78rem; border-radius: 9px; white-space: nowrap;">
                        <i data-lucide="search" style="width:13px; height:13px;"></i> Search
                    </button>
                </div>
            </div>

            <!-- ── MAIN CONTENT GRID (Fills Remaining Space) ── -->
            <div style="display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 0; flex: 1; min-height: 0; overflow: hidden;">

                <!-- ─── LEFT: LICENCE INFO PANEL ─── -->
                <div style="border-right: 1px solid var(--border-color); display: flex; flex-direction: column; background: var(--bg-app); min-height: 0; overflow: hidden;">

                    <!-- Panel Header -->
                    <div style="flex-shrink: 0; padding: 0.7rem 1.5rem; background: var(--bg-card); border-bottom: 1px solid var(--border-color); display:flex; align-items:center; gap: 0.6rem;">
                        <i data-lucide="id-card" style="width:15px; height:15px; color: var(--primary);"></i>
                        <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-main); font-family: var(--font-heading);">Licence Information</span>
                    </div>

                    <!-- Scrollable Info Rows -->
                    <div style="overflow-y: auto; flex: 1; min-height: 0; padding: 0.5rem 0;">
                        <?php
                        $lic_sections = [
                            'IDENTITY' => [
                                ['icon'=>'key-round',    'label'=>'Licence No',      'id'=>'info_licence_no',      'primary'=>true],
                                ['icon'=>'building-2',   'label'=>'Company Name',    'id'=>'info_company_name',    'bold'=>true],
                                ['icon'=>'mail',         'label'=>'Email ID',        'id'=>'info_email'],
                                ['icon'=>'phone',        'label'=>'Mobile',          'id'=>'info_mobile'],
                                ['icon'=>'user-round',   'label'=>'Contact Person',  'id'=>'info_contact_person'],
                            ],
                            'ADDRESS' => [
                                ['icon'=>'map-pin',      'label'=>'Address1',        'id'=>'info_address1'],
                                ['icon'=>'map',          'label'=>'Address2',        'id'=>'info_address2'],
                                ['icon'=>'globe',        'label'=>'Address3',        'id'=>'info_address3'],
                                ['icon'=>'map-pin-check','label'=>'City',            'id'=>'info_city'],
                                ['icon'=>'landmark',     'label'=>'State',           'id'=>'info_state'],
                                ['icon'=>'hash',         'label'=>'Pin Code',        'id'=>'info_pin_code'],
                            ],
                            'SOFTWARE' => [
                                ['icon'=>'layers',       'label'=>'Software Type',   'id'=>'info_software_type',   'badge'=>true],
                                ['icon'=>'package',      'label'=>'S/W Type',        'id'=>'info_sw_type'],
                                ['icon'=>'tag',          'label'=>'Category',        'id'=>'info_category'],
                                ['icon'=>'users',        'label'=>'No of Users',     'id'=>'info_no_of_users'],
                                ['icon'=>'user-check',   'label'=>'User Type',       'id'=>'info_user_type'],
                                ['icon'=>'building',     'label'=>'No of Companies', 'id'=>'info_no_of_companies_left'],
                            ],
                            'PARTNER & STATUS' => [
                                ['icon'=>'link',         'label'=>'Sub Partner Code','id'=>'info_subpartner_code'],
                                ['icon'=>'user-tie',     'label'=>'Sub Partner Name','id'=>'info_subpartner_name'],
                                ['icon'=>'activity',     'label'=>'Party Status',    'id'=>'info_party_status'],
                                ['icon'=>'home',         'label'=>'Home User',       'id'=>'info_home_user'],
                                ['icon'=>'shopping-bag', 'label'=>'Software Trade',  'id'=>'info_software_trade'],
                            ],
                            'FINANCIALS & DATES' => [
                                ['icon'=>'indian-rupee', 'label'=>'Total Amount',    'id'=>'info_total_amount',    'money'=>true],
                                ['icon'=>'calendar',     'label'=>'Renewal Date',    'id'=>'info_renewal_date',    'warning'=>true],
                                ['icon'=>'calendar-check','label'=>'Act On',         'id'=>'info_act_on'],
                                ['icon'=>'zap',          'label'=>'Last Hit Date',   'id'=>'info_last_hit'],
                            ],
                        ];
                        foreach ($lic_sections as $sectionTitle => $fields): ?>
                        <!-- Section Label -->
                        <div style="padding: 0.4rem 1.5rem 0.2rem; margin-top: 0.2rem;">
                            <span style="font-size: 0.62rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: var(--primary); opacity: 0.8;"><?php echo $sectionTitle; ?></span>
                        </div>
                        <?php foreach ($fields as $f):
                            $valStyle = 'font-size: 0.8rem; color: var(--text-main);';
                            if (!empty($f['primary']))  { $valStyle = 'font-size: 0.95rem; font-weight: 800; color: var(--primary); font-family: monospace;'; }
                            elseif (!empty($f['bold'])) { $valStyle = 'font-size: 0.82rem; font-weight: 700; color: var(--text-main);'; }
                            elseif (!empty($f['warning'])) { $valStyle = 'font-size: 0.8rem; font-weight: 700; color: var(--warning, #f59e0b); font-family: monospace;'; }
                            elseif (!empty($f['money'])) { $valStyle = 'font-size: 0.82rem; font-weight: 700; color: var(--success, #10b981); font-family: monospace;'; }
                            $wrapEl = !empty($f['badge'])
                                ? '<span id="' . $f['id'] . '" style="display:inline-flex; padding: 2px 9px; border-radius: 20px; background: var(--accent-light); color: var(--accent); font-weight: 700; font-size: 0.72rem;">—</span>'
                                : '<span id="' . $f['id'] . '" style="' . $valStyle . '">—</span>';
                        ?>
                        <div style="display: grid; grid-template-columns: 36px 135px 1fr; align-items: center; padding: 0.35rem 1.5rem; gap: 0.5rem; transition: background 0.15s;" onmouseover="this.style.background='var(--bg-card)'" onmouseout="this.style.background='transparent'">
                            <div style="width:28px; height:28px; border-radius: 7px; background: var(--primary-light); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i data-lucide="<?php echo $f['icon']; ?>" style="width:13px; height:13px; color: var(--primary);"></i>
                            </div>
                            <span style="font-size: 0.72rem; font-weight: 600; color: var(--text-muted);"><?php echo $f['label']; ?></span>
                            <?php echo $wrapEl; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ─── RIGHT: AMC UPDATE PANEL ─── -->
                <div style="border-left: 0; display: flex; flex-direction: column; background: var(--bg-card); min-height: 0; overflow: hidden;">

                    <!-- Panel Header -->
                    <div style="flex-shrink: 0; padding: 0.7rem 1.5rem; background: var(--border-card); border-bottom: 1px solid var(--border-color); display:flex; align-items:center; gap: 0.6rem;">
                        <i data-lucide="refresh-cw" style="width:15px; height:15px; color: var(--success, #10b981);"></i>
                        <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-main); font-family: var(--font-heading);">AMC Update</span>
                    </div>

                    <div style="overflow-y: auto; flex: 1; min-height: 0; padding: 1rem 1.5rem; display: flex; flex-direction: column; gap: 0.85rem;">

                        <!-- AMC Stats Tiles (2×2) -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem;">
                            <div style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 11px; padding: 0.75rem 0.9rem;">
                                <div style="font-size: 0.63rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px;">Basic Amount</div>
                                <div id="amc_basic_amount" style="font-size: 1rem; font-weight: 800; color: var(--text-main); font-family: monospace;">₹0.00</div>
                            </div>
                            <div style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 11px; padding: 0.75rem 0.9rem;">
                                <div style="font-size: 0.63rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px;">No of Users</div>
                                <div id="amc_no_of_users" style="font-size: 1rem; font-weight: 800; color: var(--text-main); font-family: monospace;">1</div>
                            </div>
                            <div style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 11px; padding: 0.75rem 0.9rem;">
                                <div style="font-size: 0.63rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px;">No of Companies</div>
                                <div id="amc_no_of_companies" style="font-size: 1rem; font-weight: 800; color: var(--text-main); font-family: monospace;">1</div>
                            </div>
                            <div style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 11px; padding: 0.75rem 0.9rem;">
                                <div style="font-size: 0.63rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px;">Allowed Users</div>
                                <div id="amc_allowed_users" style="font-size: 1rem; font-weight: 800; color: var(--text-main); font-family: monospace;">1</div>
                            </div>
                        </div>

                        <!-- Totals Summary -->
                        <div style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 11px; padding: 0.85rem 1.1rem; display: flex; flex-direction: column; gap: 0.5rem;">
                            <div class="flex justify-between align-center">
                                <span style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600; display:flex; align-items:center; gap:5px;"><i data-lucide="layers" style="width:12px; height:12px;"></i> Total Amount</span>
                                <strong id="amc_total_amount" style="font-family: monospace; color: var(--text-main);">₹0.00</strong>
                            </div>
                            <div class="flex justify-between align-center">
                                <span style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600; display:flex; align-items:center; gap:5px;"><i data-lucide="plus-circle" style="width:12px; height:12px;"></i> Extra User Amt</span>
                                <span style="font-family: monospace; color: var(--text-main); font-size: 0.78rem;">₹0.00</span>
                            </div>
                            <div class="flex justify-between align-center">
                                <span style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600; display:flex; align-items:center; gap:5px;"><i data-lucide="percent" style="width:12px; height:12px;"></i> GST 18%</span>
                                <span id="amc_gst_18" style="font-family: monospace; color: var(--text-main); font-size: 0.78rem;">₹0.00</span>
                            </div>
                            <div style="border-top: 1.5px dashed var(--border-color); padding-top: 0.5rem;" class="flex justify-between align-center">
                                <span style="font-size: 0.82rem; font-weight: 700; color: var(--text-main); display:flex; align-items:center; gap:5px;"><i data-lucide="check-circle" style="width:13px; height:13px; color:var(--success,#10b981);"></i> Final Amount</span>
                                <strong id="amc_final_amount" style="font-family: monospace; color: var(--success, #10b981); font-size: 1.1rem;">₹0.00</strong>
                            </div>
                        </div>

                        <!-- Feed AMC Amount -->
                        <div style="background: var(--primary-light); border: 1.5px solid var(--primary); border-radius: 11px; padding: 0.85rem 1.1rem;">
                            <div style="font-size: 0.68rem; font-weight: 700; color: var(--primary); margin-bottom: 0.55rem; text-transform: uppercase; letter-spacing: 0.05em; display:flex; align-items:center; gap:5px;">
                                <i data-lucide="calculator" style="width:13px; height:13px;"></i> Feed AMC Amount
                            </div>
                            <div class="flex align-center gap-2 flex-wrap">
                                <input type="number" step="0.01" id="feed_amc_amount_input" name="feed_amc_amount" placeholder="0.00" class="form-control form-control-focus font-mono" style="flex:1; min-width:100px; font-size: 0.82rem; border-radius: 8px; height: 36px;" oninput="calculateAmcGst()">
                                <div style="font-size: 0.68rem; font-weight: 700; color: var(--text-muted); white-space: nowrap;">+ 18% GST</div>
                                <input type="text" id="amc_calculated_total_display" name="amc_final_amount" readonly class="form-control font-mono font-bold" style="flex:1; min-width:100px; font-size: 0.82rem; border-radius: 8px; height: 36px; color: var(--success, #10b981); background: var(--bg-card);" value="" placeholder="Total incl. GST">
                                <input type="hidden" id="amc_calculated_gst_hidden" name="amc_gst_amount" value="0.00">
                            </div>
                        </div>

                        <!-- Reasons Checkboxes -->
                        <div style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 11px; padding: 0.85rem 1.1rem;">
                            <div style="font-size: 0.68rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.65rem; display:flex; align-items:center; gap:5px;">
                                <i data-lucide="list-checks" style="width:13px; height:13px;"></i> Reasons
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.45rem;">
                                <?php foreach (['Extra Services','Extra Features','Extra Training','Implementation Charges','Printing And Barcoding','Other'] as $reason): ?>
                                <label style="display:flex; align-items:center; gap: 6px; cursor:pointer; padding: 0.4rem 0.6rem; border-radius: 7px; border: 1px solid var(--border-color); background: var(--bg-card); font-size: 0.7rem; font-weight: 600; color: var(--text-main); transition: all 0.15s; user-select:none;" onmouseover="this.style.borderColor='var(--primary)'; this.style.color='var(--primary)'; this.style.background='var(--primary-light)'" onmouseout="this.style.borderColor='var(--border-color)'; this.style.color='var(--text-main)'; this.style.background='var(--bg-card)'">
                                    <input type="checkbox" name="reasons[]" value="<?php echo $reason; ?>" style="accent-color: var(--primary); width: 13px; height: 13px; flex-shrink:0;">
                                    <?php echo $reason; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <!-- ── FOOTER (ALWAYS FIXED VISIBLE AT BOTTOM) ── -->
            <div style="flex-shrink: 0; padding: 0.85rem 1.5rem; background: var(--border-card); border-top: 1px solid var(--border-color); display:flex; justify-content:flex-end; gap: 0.75rem; align-items:center;">
                <button type="button" onclick="window.closeModal('licence-amc-modal')" class="btn btn-secondary flex align-center gap-2" style="border-radius: 9px; padding: 0.5rem 1.25rem; font-size: 0.8rem;">
                    <i data-lucide="x" style="width:14px; height:14px;"></i> Close Window
                </button>
                <button type="submit" class="btn btn-success font-bold flex align-center gap-2" style="border-radius: 9px; padding: 0.5rem 1.5rem; font-size: 0.88rem; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);">
                    <i data-lucide="check-circle-2" style="width:15px; height:15px;"></i>
                    Save AMC Update
                </button>
            </div>

        </form>
    </div>
</div>
<script>
function highlightProductPill(which) {
    var lblMarg  = document.getElementById('lbl_marg_erp');
    var lblBooks = document.getElementById('lbl_marg_books');
    var base = 'display:flex;align-items:center;gap:6px;padding:0.3rem 0.75rem;border-radius:7px;cursor:pointer;font-size:0.75rem;font-weight:700;transition:all 0.2s;';
    if (which === 'marg_erp') {
        document.getElementById('radio_marg_erp').checked = true;
        lblMarg.style.cssText  = base + 'background:var(--primary);color:#fff;';
        lblBooks.style.cssText = base + 'color:var(--text-muted);';
    } else {
        document.getElementById('radio_marg_books').checked = true;
        lblBooks.style.cssText = base + 'background:var(--primary);color:#fff;';
        lblMarg.style.cssText  = base + 'color:var(--text-muted);';
    }
}
</script>

<!-- Modal 4: Edit Client Directory Record Modal — Modern Premium Redesign -->
<div id="edit-client-record-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 950px; width: 95%; max-height: 90vh; display: flex; flex-direction: column; background: var(--bg-card); color: var(--text-main); border-radius: 20px; border: 1px solid var(--border-color); box-shadow: 0 32px 64px -12px rgba(0,0,0,0.6); overflow: hidden;">
        
        <!-- HEADER (Fixed Top) -->
        <div style="flex-shrink: 0; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark, #1e40af) 100%); padding: 1rem 1.5rem;" class="flex align-center justify-between">
            <div class="flex align-center gap-3">
                <div style="background: rgba(255,255,255,0.18); backdrop-filter: blur(8px); padding: 0.55rem; border-radius: 12px; display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="edit-3" style="width:20px; height:20px; color:#fff;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 800; color: #fff; letter-spacing: -0.01em;">
                        Edit Client Directory Record
                    </h3>
                    <span style="font-size: 0.72rem; color: rgba(255,255,255,0.75);">Update client profile details, license parameters, software edition, and address.</span>
                </div>
            </div>
            <button type="button" onclick="window.closeModal('edit-client-record-modal')" style="background: rgba(255,255,255,0.15); border: none; border-radius: 10px; width: 34px; height: 34px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.28)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                <i data-lucide="x" style="width:18px; height:18px; color:#fff;"></i>
            </button>
        </div>

        <form action="index.php?page=clients&tab=directory" method="POST" style="display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden;">
            <input type="hidden" name="action" value="update_client_directory">
            <input type="hidden" id="edit_client_db_id" name="client_db_id" value="">

            <div class="modal-body p-6 flex flex-col gap-5" style="flex: 1; min-height: 0; overflow-y: auto; background: var(--bg-app);">
                
                <!-- SECTION 1: IDENTITY & BASIC INFO -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem;">
                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); margin-bottom: 0.85rem; display:flex; align-items:center; gap: 6px;">
                        <i data-lucide="id-card" style="width:14px; height:14px;"></i> Client Identity &amp; Classification
                    </div>

                    <div class="grid grid-4 gap-3">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Party Name *</label>
                            <input type="text" id="edit_party_name" name="party_name" required class="form-control text-xs" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" id="edit_customer_id_label" style="color: var(--text-main);">
                                Customer ID *
                                <span id="edit_customer_id_locked_badge" class="badge" style="display:none; --badge-bg: rgba(239,68,68,0.1); --badge-color: #dc2626; font-size: 0.65rem; margin-left: 6px; vertical-align: middle;">
                                    🔒 Non-Editable
                                </span>
                            </label>
                            <input type="text" id="edit_customer_id" name="customer_id" required class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Category</label>
                            <input type="text" id="edit_category" name="category" placeholder="e.g. Category A, B, C" class="form-control text-xs" list="category_options" style="border-radius: 8px;">
                            <datalist id="category_options">
                                <option value="Category A">
                                <option value="Category B">
                                <option value="Category C">
                                <option value="Category D">
                            </datalist>
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">S/W Type</label>
                            <input type="text" id="edit_sw_type" name="sw_type" value="Marg" class="form-control text-xs" style="border-radius: 8px;">
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: CONTACT DETAILS -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem;">
                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); margin-bottom: 0.85rem; display:flex; align-items:center; gap: 6px;">
                        <i data-lucide="phone-call" style="width:14px; height:14px;"></i> Contact &amp; Communication
                    </div>

                    <div class="grid grid-3 gap-3">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Reg Mobile</label>
                            <input type="text" id="edit_mobile" name="mobile" class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Reg Email</label>
                            <input type="email" id="edit_email" name="email" class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Contact Person</label>
                            <input type="text" id="edit_contact_person" name="contact_person" class="form-control text-xs" style="border-radius: 8px;">
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: SOFTWARE & LICENSE DETAILS -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem;">
                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); margin-bottom: 0.85rem; display:flex; align-items:center; gap: 6px;">
                        <i data-lucide="cpu" style="width:14px; height:14px;"></i> Software Parameters &amp; Commercials
                    </div>

                    <div class="grid grid-3 gap-3 mb-3">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Software Type (Edition)</label>
                            <input type="text" id="edit_software_type" name="software_type" placeholder="e.g. Marg ERP Silver" class="form-control text-xs" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">User Type</label>
                            <select id="edit_user_type" name="user_type" class="form-control text-xs" style="border-radius: 8px;">
                                <option value="Single User">Single User</option>
                                <option value="Multi User">Multi User</option>
                            </select>
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">No. of Users</label>
                            <input type="number" id="edit_no_of_users" name="no_of_users" min="1" value="1" class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>
                    </div>

                    <div class="grid grid-3 gap-3">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Software Trade</label>
                            <input type="text" id="edit_software_trade" name="software_trade" placeholder="e.g. Pharmaceutical & Chemicals" class="form-control text-xs" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Total Contract Amount (₹)</label>
                            <input type="number" step="0.01" id="edit_total_amount" name="total_amount" class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Party Status</label>
                            <select id="edit_party_status" name="party_status" class="form-control text-xs" style="border-radius: 8px;">
                                <option value="Running">Running</option>
                                <option value="Expired">Expired</option>
                                <option value="Deactive">Deactive</option>
                                <option value="Suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: ADDRESS -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem;">
                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); margin-bottom: 0.85rem; display:flex; align-items:center; gap: 6px;">
                        <i data-lucide="map-pin" style="width:14px; height:14px;"></i> Registered Location &amp; Address
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-bold" style="color: var(--text-main);">Registered Address</label>
                        <textarea id="edit_address" name="address" rows="2" class="form-control text-xs" style="border-radius: 8px; resize: vertical;"></textarea>
                    </div>

                    <div class="grid grid-3 gap-3">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">City</label>
                            <input type="text" id="edit_city" name="city" class="form-control text-xs" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">State</label>
                            <input type="text" id="edit_state" name="state" class="form-control text-xs" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Online Zip Code</label>
                            <input type="text" id="edit_online_zip_code" name="online_zip_code" class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>
                    </div>
                </div>

                <!-- SECTION 5: DATES -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem;">
                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); margin-bottom: 0.85rem; display:flex; align-items:center; gap: 6px;">
                        <i data-lucide="calendar" style="width:14px; height:14px;"></i> Key License &amp; Followup Dates
                    </div>

                    <div class="grid grid-3 gap-3">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Due On</label>
                            <input type="date" id="edit_due_on" name="due_on" class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Act On</label>
                            <input type="date" id="edit_act_on" name="act_on" class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Software Hit Date</label>
                            <input type="date" id="edit_software_hit_date" name="software_hit_date" class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>
                    </div>
                </div>

            </div>

            <!-- FOOTER (ALWAYS FIXED AT BOTTOM) -->
            <div style="flex-shrink: 0; padding: 0.85rem 1.5rem; background: var(--border-card); border-top: 1px solid var(--border-color); display:flex; justify-content:flex-end; gap: 0.75rem; align-items:center;">
                <button type="button" onclick="window.closeModal('edit-client-record-modal')" class="btn btn-secondary flex align-center gap-2" style="border-radius: 9px; padding: 0.5rem 1.25rem; font-size: 0.8rem;">
                    <i data-lucide="x" style="width:14px; height:14px;"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary font-bold flex align-center gap-2" style="border-radius: 9px; padding: 0.5rem 1.5rem; font-size: 0.85rem; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);">
                    <i data-lucide="check-circle-2" style="width:15px; height:15px;"></i>
                    <span>Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Column Header Preferences JavaScript
const defaultDirVisibleCols = [
    'col-dir-sno', 'col-dir-sw-type', 'col-dir-customer-id', 'col-dir-category', 'col-dir-party-name', 
    'col-dir-address', 'col-dir-mobile', 'col-dir-email', 'col-dir-user-type', 
    'col-dir-software-type', 'col-dir-contact-person', 'col-dir-party-status', 
    'col-dir-city', 'col-dir-software-trade', 'col-dir-total-amount', 'col-dir-actions'
];

const allDirCols = [
    'col-dir-sno', 'col-dir-sw-type', 'col-dir-customer-id', 'col-dir-category', 'col-dir-subpartner-code', 
    'col-dir-subpartner-name', 'col-dir-party-name', 'col-dir-company-using', 'col-dir-address', 
    'col-dir-mobile', 'col-dir-email', 'col-dir-user-type', 'col-dir-software-type', 
    'col-dir-no-of-users', 'col-dir-contact-person', 'col-dir-due-on', 'col-dir-act-on', 
    'col-dir-days', 'col-dir-party-status', 'col-dir-city', 'col-dir-transferred', 
    'col-dir-online-zip', 'col-dir-state', 'col-dir-home-user', 'col-dir-software-trade', 
    'col-dir-version', 'col-dir-total-amount', 'col-dir-software-hitdate', 'col-dir-wallet-id', 'col-dir-actions'
];

function loadDirColumnPreferences() {
    let saved = localStorage.getItem('client_directory_columns');
    let visibleCols = saved ? JSON.parse(saved) : defaultDirVisibleCols;

    document.querySelectorAll('.dir-col-checkbox').forEach(cb => {
        cb.checked = visibleCols.includes(cb.value);
    });

    allDirCols.forEach(colClass => {
        const isVis = visibleCols.includes(colClass);
        document.querySelectorAll('.' + colClass).forEach(el => {
            el.style.display = isVis ? '' : 'none';
        });
    });
}

function applyDirColumnPreferences() {
    let selected = [];
    document.querySelectorAll('.dir-col-checkbox:checked').forEach(cb => {
        selected.push(cb.value);
    });

    localStorage.setItem('client_directory_columns', JSON.stringify(selected));
    loadDirColumnPreferences();
    window.closeModal('manage-dir-columns-modal');
}

function selectAllDirColumns(checked) {
    document.querySelectorAll('.dir-col-checkbox').forEach(cb => {
        cb.checked = checked;
    });
}

function resetDefaultDirColumns() {
    localStorage.removeItem('client_directory_columns');
    loadDirColumnPreferences();
    window.closeModal('manage-dir-columns-modal');
}

// --------------------------------------------------------------------------
// Window 1: Open Licence & AMC Update Window (Folder Icon 📁)
// --------------------------------------------------------------------------
let currentActiveClientRecord = null;

function openLicenceAmcWindow(client) {
    currentActiveClientRecord = client;
    
    document.getElementById('amc_client_id').value = client.id || '';
    document.getElementById('win_licence_no_input').value = client.customer_id || '';
    document.getElementById('win_mobile_no_input').value = client.mobile || '';
    document.getElementById('win_firm_name_input').value = client.party_name || '';

    // Populate Left Card: All Licence Info Fields
    document.getElementById('info_licence_no').innerText           = client.customer_id || '-';
    document.getElementById('info_company_name').innerText         = client.party_name || '-';
    document.getElementById('info_email').innerText                = client.email || '-';
    document.getElementById('info_mobile').innerText               = client.mobile || '-';
    document.getElementById('info_contact_person').innerText       = client.contact_person || '-';
    document.getElementById('info_address1').innerText             = client.address || '-';
    document.getElementById('info_address2').innerText             = (client.city ? client.city + ' - ' : '') + (client.online_zip_code || '');
    document.getElementById('info_address3').innerText             = 'INDIA';
    document.getElementById('info_city').innerText                 = client.city || '-';
    document.getElementById('info_state').innerText                = client.state || '-';
    document.getElementById('info_pin_code').innerText             = client.online_zip_code || '-';
    document.getElementById('info_software_type').innerText        = client.software_type || '-';
    document.getElementById('info_sw_type').innerText              = client.sw_type || '-';
    document.getElementById('info_category').innerText             = client.category || '-';
    document.getElementById('info_no_of_users').innerText          = client.no_of_users || '-';
    document.getElementById('info_user_type').innerText            = client.user_type || '-';
    document.getElementById('info_no_of_companies_left').innerText = client.company_using || '-';
    document.getElementById('info_subpartner_code').innerText      = client.subpartner_code || '-';
    document.getElementById('info_subpartner_name').innerText      = client.subpartner_name || '-';
    document.getElementById('info_party_status').innerText         = client.party_status || '-';
    document.getElementById('info_home_user').innerText            = client.home_user || '-';
    document.getElementById('info_software_trade').innerText       = client.software_trade || '-';
    document.getElementById('info_total_amount').innerText         = client.total_amount ? '₹' + parseFloat(client.total_amount).toFixed(2) : '-';
    document.getElementById('info_renewal_date').innerText         = client.due_on || client.act_on || 'N/A';
    document.getElementById('info_act_on').innerText               = client.act_on || 'N/A';
    document.getElementById('info_last_hit').innerText             = client.software_hit_date || 'N/A';

    // Populate Right Card: AMC Info
    const totalAmt = parseFloat(client.total_amount || 0);
    document.getElementById('amc_basic_amount').innerText = '₹' + totalAmt.toFixed(2);
    document.getElementById('amc_no_of_users').innerText = client.no_of_users || 1;
    document.getElementById('amc_no_of_companies').innerText = client.company_using || 1;
    document.getElementById('amc_total_amount').innerText = '₹' + totalAmt.toFixed(2);
    document.getElementById('amc_allowed_users').innerText = client.no_of_users || 1;

    // Reset Feed AMC inputs
    document.getElementById('feed_amc_amount_input').value = '';
    document.getElementById('amc_calculated_total_display').value = '';
    document.getElementById('amc_calculated_gst_hidden').value = '0.00';
    document.getElementById('amc_gst_18').innerText = '₹0.00';
    document.getElementById('amc_final_amount').innerText = '₹' + totalAmt.toFixed(2);

    window.openModal('licence-amc-modal');
}

function calculateAmcGst() {
    const feedInput = document.getElementById('feed_amc_amount_input').value;
    const feedVal = parseFloat(feedInput) || 0;
    const gstVal = feedVal * 0.18;
    const totalVal = feedVal + gstVal;

    document.getElementById('amc_gst_18').innerText = '₹' + gstVal.toFixed(2);
    document.getElementById('amc_calculated_total_display').value = totalVal > 0 ? '₹' + totalVal.toFixed(2) : '';
    document.getElementById('amc_calculated_gst_hidden').value = gstVal.toFixed(2);

    const baseAmt = parseFloat(currentActiveClientRecord ? currentActiveClientRecord.total_amount : 0) || 0;
    const finalTotal = baseAmt + totalVal;
    document.getElementById('amc_final_amount').innerText = '₹' + finalTotal.toFixed(2);
}

function performWinLicenceSearch() {
    const licNo = document.getElementById('win_licence_no_input').value.trim().toLowerCase();
    const mob = document.getElementById('win_mobile_no_input').value.trim().toLowerCase();
    const firm = document.getElementById('win_firm_name_input').value.trim().toLowerCase();

    const dirRecords = <?php echo json_encode($dir_records); ?>;
    
    let matched = dirRecords.find(r => {
        if (licNo && r.customer_id && r.customer_id.toLowerCase().includes(licNo)) return true;
        if (mob && r.mobile && r.mobile.toLowerCase().includes(mob)) return true;
        if (firm && r.party_name && r.party_name.toLowerCase().includes(firm)) return true;
        return false;
    });

    if (matched) {
        openLicenceAmcWindow(matched);
    } else {
        alert('No matching client licence record found for search query.');
    }
}

// --------------------------------------------------------------------------
// Window 2: Open Edit Client Record Modal (Edit Icon ✏️)
// --------------------------------------------------------------------------
function openEditClientRecordModal(client) {
    document.getElementById('edit_client_db_id').value = client.id || '';
    document.getElementById('edit_party_name').value = client.party_name || '';
    document.getElementById('edit_customer_id').value = client.customer_id || '';
    document.getElementById('edit_category').value = client.category || 'Category A';
    document.getElementById('edit_sw_type').value = client.sw_type || 'Marg';
    document.getElementById('edit_mobile').value = client.mobile || '';
    document.getElementById('edit_email').value = client.email || '';
    document.getElementById('edit_contact_person').value = client.contact_person || '';
    document.getElementById('edit_software_type').value = client.software_type || '';
    document.getElementById('edit_user_type').value = client.user_type || 'Single User';
    document.getElementById('edit_no_of_users').value = client.no_of_users || 1;
    document.getElementById('edit_address').value = client.address || '';
    document.getElementById('edit_city').value = client.city || '';
    document.getElementById('edit_state').value = client.state || '';
    document.getElementById('edit_online_zip_code').value = client.online_zip_code || '';
    document.getElementById('edit_software_trade').value = client.software_trade || '';
    document.getElementById('edit_total_amount').value = client.total_amount || 0;
    document.getElementById('edit_party_status').value = client.party_status || 'Running';
    document.getElementById('edit_due_on').value = client.due_on || '';
    document.getElementById('edit_act_on').value = client.act_on || '';
    document.getElementById('edit_software_hit_date').value = client.software_hit_date || '';

    // Lock Customer ID field if it already has a value (non-editable for existing clients)
    var custIdInput = document.getElementById('edit_customer_id');
    var custIdBadge = document.getElementById('edit_customer_id_locked_badge');
    if (client.customer_id && client.customer_id.trim() !== '') {
        custIdInput.setAttribute('readonly', 'readonly');
        custIdInput.style.background = 'var(--bg-card, #f8f8f8)';
        custIdInput.style.color = 'var(--text-muted, #888)';
        custIdInput.style.cursor = 'not-allowed';
        custIdInput.style.borderColor = 'var(--border-color)';
        if (custIdBadge) custIdBadge.style.display = 'inline-block';
    } else {
        custIdInput.removeAttribute('readonly');
        custIdInput.style.background = '';
        custIdInput.style.color = '';
        custIdInput.style.cursor = '';
        custIdInput.style.borderColor = '';
        if (custIdBadge) custIdBadge.style.display = 'none';
    }

    window.openModal('edit-client-record-modal');
}

// --------------------------------------------------------------------------
// Window 3: Open Client Account, Login & Access Modal (Key Icon 🔑)
// --------------------------------------------------------------------------
function openClientAccountModal(clientData) {
    document.getElementById('ca_client_id').value = clientData.id || '';
    document.getElementById('ca_customer_id').value = clientData.customer_id || '';
    document.getElementById('ca_party_name').value = clientData.party_name || clientData.name || '';
    document.getElementById('ca_email').value = clientData.email || '';
    document.getElementById('ca_password').value = '';
    document.getElementById('ca_party_status').value = clientData.party_status || clientData.user_status || 'Running';
    document.getElementById('ca_software_type').value = clientData.software_type || 'Marg ERP Silver';
    document.getElementById('ca_no_of_users').value = clientData.no_of_users || 1;
    document.getElementById('ca_due_on').value = clientData.due_on || '';
    document.getElementById('ca_total_amount').value = clientData.total_amount || '4661.00';

    var perms = ['dashboard', 'quotation', 'payments', 'support', 'renewals', 'bot_flows'];
    if (clientData.permissions) {
        if (typeof clientData.permissions === 'string') {
            try { perms = JSON.parse(clientData.permissions); } catch(e) {}
        } else if (Array.isArray(clientData.permissions)) {
            perms = clientData.permissions;
        }
    }
    
    document.querySelectorAll('#clientAccountModal input[name="modules[]"]').forEach(function(cb) {
        cb.checked = perms.includes(cb.value);
    });

    document.getElementById('clientAccountModal').style.display = 'flex';
}

function closeClientAccountModal() {
    document.getElementById('clientAccountModal').style.display = 'none';
}

function generateTempPassword() {
    var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789#@!';
    var pass = '';
    for (var i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('ca_password').value = pass;
}

document.addEventListener('DOMContentLoaded', loadDirColumnPreferences);
</script>

<!-- Client Credentials, Subscription Plan & Feature Access Modal -->
<div id="clientAccountModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); z-index: 99999; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card, #ffffff); border-radius: 14px; width: 100%; max-width: 680px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,0.3); border: 1px solid var(--border-color);">
        
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--border-card);">
            <div>
                <h3 style="margin: 0; font-family: var(--font-heading); font-size: 1.2rem; color: var(--text-main);" id="ca_modal_title">Client Account Details, Credentials & Controls</h3>
                <span class="text-xs text-muted">Manage login credentials, software plan, and module permissions.</span>
            </div>
            <button type="button" onclick="closeClientAccountModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="index.php?page=clients" method="POST" style="padding: 1.5rem;">
            <input type="hidden" name="action" value="save_client_account">
            <input type="hidden" name="client_id" id="ca_client_id" value="">

            <!-- 1. LOGIN CREDENTIALS SECTION -->
            <div style="background: rgba(59,130,246,0.04); border: 1px solid rgba(59,130,246,0.15); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem;">
                <h4 style="margin: 0 0 1rem 0; font-size: 0.825rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary); display: flex; align-items: center; gap: 6px;">
                    <i data-lucide="shield-check" style="width: 16px; height: 16px;"></i> 1. Client Login Credentials & Account Status
                </h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="form-label text-xs font-semibold">Party / Client Name</label>
                        <input type="text" name="party_name" id="ca_party_name" class="form-control text-xs" required>
                    </div>
                    <div>
                        <label class="form-label text-xs font-semibold">Login Email / Username</label>
                        <input type="email" name="email" id="ca_email" class="form-control text-xs" required>
                    </div>
                    <div>
                        <label class="form-label text-xs font-semibold">Set / Reset Password</label>
                        <div style="display: flex; gap: 0.5rem;">
                            <input type="text" name="password" id="ca_password" class="form-control text-xs" placeholder="Leave blank to keep unchanged">
                            <button type="button" onclick="generateTempPassword()" class="btn btn-secondary text-xs" style="white-space: nowrap;">Generate</button>
                        </div>
                        <span class="text-xs text-muted" style="font-size: 0.7rem;">Default initial password: <code>client123</code></span>
                    </div>
                    <div>
                        <label class="form-label text-xs font-semibold">Account Status (Admin Approved)</label>
                        <select name="party_status" id="ca_party_status" class="form-control text-xs font-semibold" required>
                            <option value="Running">Active (Approved)</option>
                            <option value="Pending Approval">Pending Admin Approval</option>
                            <option value="Deactive">Deactive</option>
                            <option value="Suspended">Suspended / Declined</option>
                            <option value="Expired">Expired</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 2. SUBSCRIPTION PLAN & LICENSE SECTION -->
            <div style="background: rgba(16,185,129,0.04); border: 1px solid rgba(16,185,129,0.15); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem;">
                <h4 style="margin: 0 0 1rem 0; font-size: 0.825rem; text-transform: uppercase; letter-spacing: 0.05em; color: #10b981; display: flex; align-items: center; gap: 6px;">
                    <i data-lucide="package" style="width: 16px; height: 16px;"></i> 2. Purchased Software Plan & Subscription License
                </h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="form-label text-xs font-semibold">Customer ID</label>
                        <input type="text" name="customer_id" id="ca_customer_id" class="form-control text-xs font-mono">
                    </div>
                    <div>
                        <label class="form-label text-xs font-semibold">Purchased Software / Plan</label>
                        <select name="software_type" id="ca_software_type" class="form-control text-xs font-semibold">
                            <option value="Marg ERP Silver">Marg ERP Silver</option>
                            <option value="Marg ERP Gold">Marg ERP Gold</option>
                            <option value="Marg ERP Diamond">Marg ERP Diamond</option>
                            <option value="WhatsApp Bot Plan">WhatsApp Bot & Flow Plan</option>
                            <option value="Full CRM Enterprise">Full CRM Enterprise</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label text-xs font-semibold">User License Count</label>
                        <input type="number" name="no_of_users" id="ca_no_of_users" class="form-control text-xs" min="1" value="1">
                    </div>
                    <div>
                        <label class="form-label text-xs font-semibold">Plan Due / Expiry Date</label>
                        <input type="date" name="due_on" id="ca_due_on" class="form-control text-xs">
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold">Total Amount (₹)</label>
                        <input type="number" step="0.01" name="total_amount" id="ca_total_amount" class="form-control text-xs" value="4661.00">
                    </div>
                </div>
            </div>

            <!-- 3. FEATURE ACCESS PERMISSIONS ("WHAT THEY ACCESS") -->
            <div style="background: rgba(139,92,246,0.04); border: 1px solid rgba(139,92,246,0.15); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem;">
                <h4 style="margin: 0 0 1rem 0; font-size: 0.825rem; text-transform: uppercase; letter-spacing: 0.05em; color: #8b5cf6; display: flex; align-items: center; gap: 6px;">
                    <i data-lucide="lock" style="width: 16px; height: 16px;"></i> 3. Client Accessible Modules ("What They Access")
                </h4>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; cursor: pointer;">
                        <input type="checkbox" name="modules[]" value="dashboard" id="mod_dashboard" checked>
                        <span>Dashboard</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; cursor: pointer;">
                        <input type="checkbox" name="modules[]" value="quotation" id="mod_quotation" checked>
                        <span>Quotations</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; cursor: pointer;">
                        <input type="checkbox" name="modules[]" value="payments" id="mod_payments" checked>
                        <span>Payments & Invoices</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; cursor: pointer;">
                        <input type="checkbox" name="modules[]" value="support" id="mod_support" checked>
                        <span>Support Tickets</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; cursor: pointer;">
                        <input type="checkbox" name="modules[]" value="renewals" id="mod_renewals" checked>
                        <span>Renewals Manager</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; cursor: pointer;">
                        <input type="checkbox" name="modules[]" value="bot_flows" id="mod_bot_flows" checked>
                        <span>WhatsApp Bots & Flows</span>
                    </label>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeClientAccountModal()">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs" style="font-weight: 600; padding: 0.6rem 1.25rem;">
                    <i data-lucide="check-circle" style="width: 16px; height: 16px;"></i> Save Credentials & Privileges
                </button>
            </div>
        </form>
    </div>
</div>
