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
        'S.No', 'S/W Type', 'CUSTOMER ID', 'SubPartner Code', 'SubPartner Name', 
        'Party Name', 'CompanyUsing', 'Address', 'Mobile', 'EmailID', 
        'User', 'Type', 'NoOfUser', 'Contact Person', 'Due On', 
        'Act On', 'Days', 'Party Status', 'City', 'Transferred Party', 
        'OnlineZipCode', 'State', 'Home User', 'Software Trade', 'Version', 
        'Total Amount', 'Software HitDate', 'Wallet Id'
    ];
    fputcsv($output, $headers);
    
    // Sample Row 1
    fputcsv($output, [
        '1', 'Marg', '1352947', '', '', 
        'GANTAVYA PHARMACY', '4', 'SIS HOSPITAL 3 COM 1/9 AMBEDKAR PURAM AWAS VIKAS NO.3, KALYANPUR, KANPUR NAGAR-208017 UTTAR PRADESH, INDIA', '9340000000', 'sishospitalniramay@gmail.com', 
        'Multi User', 'Marg ERP Silver', '2', 'Mr. RAJESH', '', 
        '', '-559', 'Running', 'Kanpur', 'No', 
        '208017', 'Uttar Pradesh', 'No', 'Pharmaceutical & Chemicals', '', 
        '4661.00', '', ''
    ]);
    
    // Sample Row 2
    fputcsv($output, [
        '2', 'Marg', '1352948', 'SP-01', 'North Zone Partner', 
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
        'S.No', 'S/W Type', 'CUSTOMER ID', 'SubPartner Code', 'SubPartner Name', 
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
                $row['sno'], $row['sw_type'], $row['customer_id'], $row['subpartner_code'], $row['subpartner_name'],
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
                            sno, sw_type, customer_id, subpartner_code, subpartner_name, party_name, company_using,
                            address, mobile, email, user_type, software_type, no_of_users, contact_person,
                            due_on, act_on, days, party_status, city, transferred_party, online_zip_code,
                            state, home_user, software_trade, version, total_amount, software_hit_date, wallet_id
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?
                        )
                    ");

                    $updateStmt = $pdo->prepare("
                        UPDATE client_directory SET
                            sno = ?, sw_type = ?, subpartner_code = ?, subpartner_name = ?, party_name = ?, company_using = ?,
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
                                    $sno, $sw_type, $subpartner_code, $subpartner_name, $party_name, $company_using,
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
                                $sno, $sw_type, $customer_id, $subpartner_code, $subpartner_name, $party_name, $company_using,
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
    
    if ($id > 0 && !empty($party_name) && $pdo) {
        try {
            $stmt = $pdo->prepare("
                UPDATE client_directory SET
                    sw_type = ?, customer_id = ?, subpartner_code = ?, subpartner_name = ?,
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

        $dir_where = [];
        $dir_params = [];

        if (!empty($search_query)) {
            $dir_where[] = "(party_name LIKE ? OR customer_id LIKE ? OR mobile LIKE ? OR email LIKE ? OR address LIKE ? OR city LIKE ? OR state LIKE ? OR contact_person LIKE ?)";
            $st = '%' . $search_query . '%';
            for ($i = 0; $i < 8; $i++) $dir_params[] = $st;
        }

        if (!empty($status_filter)) {
            $dir_where[] = "LOWER(party_status) = ?";
            $dir_params[] = strtolower($status_filter);
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

        <!-- KPI Metrics Row -->
        <div class="grid grid-4 gap-4 mb-6">
            <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
                <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="users" style="width: 24px; height: 24px;"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Total Client Records</span>
                    <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--text-main);"><?php echo number_format($dir_total_count); ?></span>
                </div>
            </div>

            <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
                <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--success-light); color: var(--success); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="check-circle" style="width: 24px; height: 24px;"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Running Party Accounts</span>
                    <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--success);"><?php echo number_format($dir_running_count); ?></span>
                </div>
            </div>

            <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
                <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--accent-light); color: var(--accent); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="indian-rupee" style="width: 24px; height: 24px;"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Total Directory Value</span>
                    <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--accent);">₹<?php echo number_format($dir_total_val); ?></span>
                </div>
            </div>

            <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
                <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--warning-light); color: var(--warning); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="layers" style="width: 24px; height: 24px;"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Software Trade Types</span>
                    <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--warning);"><?php echo count($dir_trade_types); ?></span>
                </div>
            </div>
        </div>

        <!-- Search & Filter Panel for Directory -->
        <div class="card p-5 mb-6" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-lg);">
            <form action="index.php" method="GET" class="flex flex-col gap-4">
                <input type="hidden" name="page" value="clients">
                <input type="hidden" name="tab" value="directory">

                <div class="flex justify-between align-center border-bottom pb-3" style="border-bottom: 1px solid var(--border-color);">
                    <div class="flex align-center gap-2">
                        <i data-lucide="search" style="width: 18px; height: 18px; color: var(--primary);"></i>
                        <h3 class="m-0 text-sm font-bold" style="font-family: var(--font-heading);">Filter Client Directory Data</h3>
                    </div>
                    <?php if (!empty($search_query) || !empty($status_filter) || !empty($trade_filter)): ?>
                        <a href="index.php?page=clients&tab=directory" class="btn btn-secondary text-xs text-danger" style="padding: 0.3rem 0.75rem;">
                            <i data-lucide="rotate-ccw" style="width: 12px; height: 12px;"></i>
                            <span>Clear Search Filters</span>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="grid" style="grid-template-columns: 2fr 1fr 1fr 1fr; gap: 1rem; align-items: end;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Search Client Directory</label>
                        <div style="position: relative;">
                            <input type="text" name="search" class="form-control form-control-focus text-sm" placeholder="Party Name, Customer ID, Mobile, Email, Address, City..." value="<?php echo htmlspecialchars($search_query); ?>" style="padding-left: 2.25rem;">
                            <i data-lucide="search" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-muted);"></i>
                        </div>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Party Status</label>
                        <select name="status" class="form-control form-control-focus text-sm">
                            <option value="">All Statuses</option>
                            <option value="Running" <?php echo (strcasecmp($status_filter, 'Running') === 0) ? 'selected' : ''; ?>>Running</option>
                            <option value="Expired" <?php echo (strcasecmp($status_filter, 'Expired') === 0) ? 'selected' : ''; ?>>Expired</option>
                            <option value="Deactive" <?php echo (strcasecmp($status_filter, 'Deactive') === 0) ? 'selected' : ''; ?>>Deactive</option>
                            <option value="Suspended" <?php echo (strcasecmp($status_filter, 'Suspended') === 0) ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Software Trade</label>
                        <select name="trade" class="form-control form-control-focus text-sm">
                            <option value="">All Trade Categories</option>
                            <?php foreach ($dir_trade_types as $tr): ?>
                                <option value="<?php echo htmlspecialchars($tr); ?>" <?php echo ($trade_filter === $tr) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tr); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <button type="submit" class="btn btn-primary text-sm" style="width: 100%; padding: 0.65rem 1rem;">
                            <i data-lucide="filter" style="width: 16px; height: 16px;"></i>
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
                                <td colspan="29" class="text-center text-muted py-8">
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
                            <?php foreach ($dir_records as $r): 
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
                            ?>
                                <tr>
                                    <td class="col-dir-sno" style="padding: 0.75rem; color: var(--text-muted); font-family: monospace;"><?php echo htmlspecialchars($r['sno'] ?? $r['id']); ?></td>
                                    <td class="col-dir-sw-type"><span class="badge text-xs" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);"><?php echo htmlspecialchars($r['sw_type'] ?? 'Marg'); ?></span></td>
                                    <td class="col-dir-customer-id"><span class="font-bold text-primary font-mono"><?php echo htmlspecialchars($r['customer_id']); ?></span></td>
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
                                        <div class="flex align-center justify-end gap-1">
                                            <!-- Login & Access Credentials Modal Button -->
                                            <button type="button" class="btn btn-primary text-xs" style="padding: 0.25rem 0.5rem; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;" title="Manage Client Credentials, Software Plan & Module Access Permissions" onclick='openClientAccountModal(<?php echo $rJson; ?>)'>
                                                <i data-lucide="key" style="width: 13px; height: 13px;"></i> Login & Access
                                            </button>
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

            <!-- Pagination Bar for Directory -->
            <?php if ($limit !== 'all' && $dir_total_pages > 1): ?>
                <div class="p-4 flex justify-between align-center" style="border-top: 1px solid var(--border-color); background-color: var(--border-card);">
                    <span class="text-xs text-muted">Showing Directory Page <?php echo $page_num; ?> of <?php echo $dir_total_pages; ?></span>
                    <div class="flex gap-1">
                        <?php if ($page_num > 1): ?>
                            <a href="<?php echo getClientsPageUrl('directory', $page_num - 1, $limit); ?>" class="btn btn-secondary text-xs">Previous</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $dir_total_pages; $i++): ?>
                            <a href="<?php echo getClientsPageUrl('directory', $i, $limit); ?>" class="btn btn-secondary text-xs <?php echo ($i == $page_num) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page_num < $dir_total_pages): ?>
                            <a href="<?php echo getClientsPageUrl('directory', $page_num + 1, $limit); ?>" class="btn btn-secondary text-xs">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- ==================================================================== -->
        <!-- TAB 2: ACTIVE CRM CLIENT ACCOUNTS (leads TABLE)                      -->
        <!-- ==================================================================== -->

        <!-- KPI Summary Row for CRM Accounts -->
        <div class="grid grid-4 gap-4 mb-6">
            <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
                <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="building-2" style="width: 24px; height: 24px;"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Total CRM Clients</span>
                    <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--text-main);"><?php echo number_format($crm_total_count); ?></span>
                </div>
            </div>

            <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
                <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--success-light); color: var(--success); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="check-circle-2" style="width: 24px; height: 24px;"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Installed & Active</span>
                    <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--success);"><?php echo number_format($crm_installed_count); ?></span>
                </div>
            </div>

            <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
                <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--accent-light); color: var(--accent); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="indian-rupee" style="width: 24px; height: 24px;"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Portfolio Contract Value</span>
                    <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--accent);">₹<?php echo number_format($crm_portfolio_val / 100000, 2); ?>L</span>
                </div>
            </div>

            <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
                <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--warning-light); color: var(--warning); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="user-check" style="width: 24px; height: 24px;"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Account Reps</span>
                    <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--warning);"><?php echo count($operators_list); ?></span>
                </div>
            </div>
        </div>

        <!-- CRM Clients Search Panel -->
        <div class="card p-5 mb-6" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-lg);">
            <form action="index.php" method="GET" class="flex flex-col gap-4">
                <input type="hidden" name="page" value="clients">
                <input type="hidden" name="tab" value="crm">
                
                <div class="flex justify-between align-center border-bottom pb-3" style="border-bottom: 1px solid var(--border-color);">
                    <div class="flex align-center gap-2">
                        <i data-lucide="search-code" style="width: 18px; height: 18px; color: var(--primary);"></i>
                        <h3 class="m-0 text-sm font-bold" style="font-family: var(--font-heading);">Filter CRM Accounts</h3>
                    </div>
                    <?php if (!empty($search_query) || !empty($status_filter) || !empty($product_filter) || !empty($operator_filter)): ?>
                        <a href="index.php?page=clients&tab=crm" class="btn btn-secondary text-xs text-danger" style="padding: 0.3rem 0.75rem;">
                            <i data-lucide="rotate-ccw" style="width: 12px; height: 12px;"></i>
                            <span>Clear All Filters</span>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="grid" style="grid-template-columns: 2fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: end;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Search Client Accounts</label>
                        <div style="position: relative;">
                            <input type="text" name="search" class="form-control form-control-focus text-sm" placeholder="Company, Contact Person, Phone, Email, GSTIN, City..." value="<?php echo htmlspecialchars($search_query); ?>" style="padding-left: 2.25rem;">
                            <i data-lucide="search" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-muted);"></i>
                        </div>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Client Status</label>
                        <select name="status" class="form-control form-control-focus text-sm">
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
                        <label class="form-label text-xs font-semibold">Software Product</label>
                        <select name="product" class="form-control form-control-focus text-sm">
                            <option value="">All Products</option>
                            <?php foreach ($products_options as $pOpt): ?>
                                <option value="<?php echo htmlspecialchars($pOpt); ?>" <?php echo (strcasecmp($product_filter, $pOpt) === 0) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pOpt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Account Representative</label>
                        <select name="operator" class="form-control form-control-focus text-sm">
                            <option value="">All Operators</option>
                            <?php foreach ($operators_list as $op): ?>
                                <option value="<?php echo htmlspecialchars($op); ?>" <?php echo ($operator_filter === $op) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($op); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <button type="submit" class="btn btn-primary text-sm" style="width: 100%; padding: 0.65rem 1rem;">
                            <i data-lucide="filter" style="width: 16px; height: 16px;"></i>
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

            <!-- Pagination Controls Bar -->
            <?php if ($limit !== 'all' && $crm_total_pages > 1): ?>
                <div class="p-4 flex justify-between align-center" style="border-top: 1px solid var(--border-color); background-color: var(--border-card);">
                    <span class="text-xs text-muted">Showing Page <?php echo $page_num; ?> of <?php echo $crm_total_pages; ?></span>
                    <div class="flex gap-1">
                        <?php if ($page_num > 1): ?>
                            <a href="<?php echo getClientsPageUrl('crm', $page_num - 1, $limit); ?>" class="btn btn-secondary text-xs">Previous</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $crm_total_pages; $i++): ?>
                            <a href="<?php echo getClientsPageUrl('crm', $i, $limit); ?>" class="btn btn-secondary text-xs <?php echo ($i == $page_num) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page_num < $crm_total_pages): ?>
                            <a href="<?php echo getClientsPageUrl('crm', $page_num + 1, $limit); ?>" class="btn btn-secondary text-xs">Next</a>
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

<!-- Modal 3: Client Licence & AMC Information Window (Matching Design System & Exact Side-by-Side Layout from Screenshot 1) -->
<div id="licence-amc-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 1180px; width: 95%; background: var(--bg-card); color: var(--text-main); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7); overflow: hidden;">
        
        <!-- Window Header Bar -->
        <div style="background-color: var(--border-card); padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);" class="flex align-center justify-between">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 0.6rem; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="folder" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.25rem; font-weight: 800; color: var(--text-main);">
                        Client Licence & AMC Workspace
                    </h3>
                    <span class="text-xs text-muted">Review active software license information and execute AMC fee updates.</span>
                </div>
            </div>
            <button class="btn-icon" onclick="window.closeModal('licence-amc-modal')">
                <i data-lucide="x" style="width: 20px; height: 20px;"></i>
            </button>
        </div>

        <form action="index.php?page=clients&tab=directory" method="POST">
            <input type="hidden" name="action" value="update_client_amc">
            <input type="hidden" id="amc_client_id" name="amc_client_id" value="">

            <div class="p-6 flex flex-col gap-5" style="background-color: var(--bg-app);">
                
                <!-- Radio Selection & Top Search Row -->
                <div class="flex flex-col gap-3">
                    <!-- Top Radio Selectors -->
                    <div class="flex align-center gap-6">
                        <label class="flex align-center gap-2 pointer text-sm font-extrabold" style="color: var(--text-main);">
                            <input type="radio" name="sw_product_type" value="MARG ERP" checked style="accent-color: var(--primary); scale: 1.2;">
                            <span>MARG ERP</span>
                        </label>
                        <label class="flex align-center gap-2 pointer text-sm font-extrabold" style="color: var(--text-muted);">
                            <input type="radio" name="sw_product_type" value="Marg Books" style="accent-color: var(--primary); scale: 1.2;">
                            <span>Marg Books</span>
                        </label>
                    </div>

                    <!-- Search Input Row -->
                    <div class="flex align-center gap-3 flex-wrap">
                        <input type="text" id="win_licence_no_input" placeholder="Licence No" class="form-control form-control-focus text-xs font-mono" style="width: 170px; background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main);">
                        <input type="text" id="win_mobile_no_input" placeholder="Mobile No" class="form-control form-control-focus text-xs font-mono" style="width: 170px; background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main);">
                        <input type="text" id="win_firm_name_input" placeholder="Firm Name" class="form-control form-control-focus text-xs" style="width: 220px; background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main);">
                        <button type="button" onclick="performWinLicenceSearch()" class="btn btn-primary text-xs font-bold px-4" style="height: 36px; padding: 0 1.25rem;">
                            SEARCH
                        </button>
                    </div>
                </div>

                <!-- Two Main Side-By-Side Section Grid (Matching Screenshot 1 Layout Exactly) -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; align-items: stretch;">
                    
                    <!-- LEFT CARD: Licence Information: -->
                    <div style="background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); overflow: hidden; display: flex; flex-direction: column;">
                        <div style="background-color: var(--border-card); padding: 0.6rem 1rem; border-bottom: 1px solid var(--border-color);">
                            <h4 class="m-0 text-xs font-extrabold text-main" style="text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-heading);">
                                Licence Information:
                            </h4>
                        </div>

                        <div class="p-4 flex flex-col gap-2 text-xs" style="flex: 1;">
                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: center;">
                                <span class="text-muted font-semibold">Licence No:</span>
                                <strong id="info_licence_no" class="font-mono text-primary font-bold text-sm">-</strong>
                            </div>

                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: center;">
                                <span class="text-muted font-semibold">Company Name:</span>
                                <strong id="info_company_name" class="text-main font-bold text-sm">-</strong>
                            </div>

                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: center;">
                                <span class="text-muted font-semibold">Reg Email ID:</span>
                                <span id="info_email" class="font-mono text-main">-</span>
                            </div>

                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: center;">
                                <span class="text-muted font-semibold">Reg Mobile:</span>
                                <span id="info_mobile" class="font-mono text-main">-</span>
                            </div>

                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: flex-start;">
                                <span class="text-muted font-semibold">Address1:</span>
                                <span id="info_address1" class="text-main">-</span>
                            </div>

                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: flex-start;">
                                <span class="text-muted font-semibold">Address2:</span>
                                <span id="info_address2" class="text-main">-</span>
                            </div>

                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: flex-start;">
                                <span class="text-muted font-semibold">Address3:</span>
                                <span id="info_address3" class="text-main">-</span>
                            </div>

                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: center;">
                                <span class="text-muted font-semibold">Software Type:</span>
                                <span id="info_software_type" class="badge text-xs" style="--badge-bg: var(--accent-light); --badge-color: var(--accent); font-weight: 700; width: fit-content;">-</span>
                            </div>

                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: center;">
                                <span class="text-muted font-semibold">Renewal Date:</span>
                                <span id="info_renewal_date" class="font-mono text-warning font-bold">-</span>
                            </div>

                            <div style="display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; align-items: center;">
                                <span class="text-muted font-semibold">Last Hit Date:</span>
                                <span id="info_last_hit" class="font-mono text-main">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT CARD: AMC Update Information: -->
                    <div style="background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); overflow: hidden; display: flex; flex-direction: column;">
                        <div style="background-color: var(--border-card); padding: 0.6rem 1rem; border-bottom: 1px solid var(--border-color);">
                            <h4 class="m-0 text-xs font-extrabold text-main" style="text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-heading);">
                                AMC Update Information:
                            </h4>
                        </div>

                        <div class="p-4 flex flex-col gap-3 text-xs" style="flex: 1;">
                            
                            <!-- Field Row 1 -->
                            <div class="grid grid-2 gap-4">
                                <div class="flex align-center gap-2"><span class="text-muted font-semibold">Basic Amount :</span> <strong id="amc_basic_amount" class="text-main font-mono">₹0.00</strong></div>
                                <div class="flex align-center justify-end gap-2"><span class="text-muted font-semibold">No Of Users :</span> <strong id="amc_no_of_users" class="text-main font-mono">1</strong></div>
                            </div>

                            <!-- Field Row 2 -->
                            <div class="flex justify-end">
                                <div class="flex align-center gap-2"><span class="text-muted font-semibold">No Of Companies :</span> <span id="amc_no_of_companies" class="text-main font-mono">1</span></div>
                            </div>

                            <!-- Field Row 3 -->
                            <div class="grid grid-2 gap-4">
                                <div class="flex align-center gap-2"><span class="text-muted font-semibold">Extra User Amt :</span> <span class="text-main font-mono">₹0.00</span></div>
                                <div class="flex align-center justify-end gap-2"><span class="text-muted font-semibold">Total Amount :</span> <strong id="amc_total_amount" class="text-main font-mono">₹0.00</strong></div>
                            </div>

                            <!-- Field Row 4 -->
                            <div class="grid grid-2 gap-4">
                                <div class="flex align-center gap-2"><span class="text-muted font-semibold">Allowed Users :</span> <span id="amc_allowed_users" class="text-main font-mono">1</span></div>
                                <div class="flex align-center justify-end gap-2"><span class="text-muted font-semibold">GST 18% :</span> <span id="amc_gst_18" class="text-main font-mono">₹0.00</span></div>
                            </div>

                            <!-- Field Row 5 -->
                            <div>
                                <div class="flex align-center gap-2"><span class="text-muted font-semibold">Final Amount :</span> <strong id="amc_final_amount" class="text-success font-extrabold text-sm font-mono">₹0.00</strong></div>
                            </div>

                            <!-- Feed AMC Amount Calculation Row (Matching Screenshot 1 Layout Exactly) -->
                            <div class="flex align-center gap-2 py-2 flex-wrap" style="border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); margin: 0.25rem 0;">
                                <span class="text-muted font-semibold" style="width: 140px;">Feed AMC Amount :</span>
                                <input type="number" step="0.01" id="feed_amc_amount_input" name="feed_amc_amount" placeholder="" class="form-control form-control-focus text-xs font-mono" style="width: 140px; background-color: var(--bg-app); border: 1px solid var(--border-color); color: var(--text-main);" oninput="calculateAmcGst()">
                                <span class="font-bold text-xs text-muted" style="margin: 0 0.25rem;">+ 18% GST</span>
                                <input type="text" id="amc_calculated_total_display" name="amc_final_amount" readonly class="form-control text-xs font-extrabold font-mono text-success" style="width: 140px; background-color: var(--bg-app); border: 1px solid var(--border-color);" value="">
                                <input type="hidden" id="amc_calculated_gst_hidden" name="amc_gst_amount" value="0.00">
                            </div>

                            <!-- Reasons Checkboxes Matrix (Matching Screenshot 1 Layout Exactly) -->
                            <div class="flex gap-2" style="align-items: start;">
                                <span class="text-muted font-semibold" style="width: 140px; flex-shrink: 0;">Reasons :</span>
                                <div class="flex flex-col gap-2">
                                    <div class="flex align-center gap-3 flex-wrap text-xs">
                                        <label class="flex align-center gap-1 pointer"><input type="checkbox" name="reasons[]" value="Extra Services" style="accent-color: var(--primary); scale: 1.1;"> <span class="text-main">Extra Services</span></label>
                                        <label class="flex align-center gap-1 pointer"><input type="checkbox" name="reasons[]" value="Extra Features" style="accent-color: var(--primary); scale: 1.1;"> <span class="text-main">Extra Features</span></label>
                                        <label class="flex align-center gap-1 pointer"><input type="checkbox" name="reasons[]" value="Extra Training" style="accent-color: var(--primary); scale: 1.1;"> <span class="text-main">Extra Training</span></label>
                                    </div>
                                    <div class="flex align-center gap-3 flex-wrap text-xs">
                                        <label class="flex align-center gap-1 pointer"><input type="checkbox" name="reasons[]" value="Implementation Charges" style="accent-color: var(--primary); scale: 1.1;"> <span class="text-main">Implementation Charges</span></label>
                                        <label class="flex align-center gap-1 pointer"><input type="checkbox" name="reasons[]" value="Printing And Barcoding" style="accent-color: var(--primary); scale: 1.1;"> <span class="text-main">Printing And Barcoding</span></label>
                                        <label class="flex align-center gap-1 pointer"><input type="checkbox" name="reasons[]" value="Other" style="accent-color: var(--primary); scale: 1.1;"> <span class="text-main">Other</span></label>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>

            </div>

            <div class="modal-footer p-4 flex justify-end gap-2" style="background-color: var(--border-card); border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('licence-amc-modal')">Close Window</button>
                <button type="submit" class="btn btn-success text-sm font-bold flex align-center gap-2">
                    <i data-lucide="check-circle-2" style="width: 16px; height: 16px;"></i>
                    <span>Save AMC Update</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 4: Edit Client Directory Record Modal (Triggered by Edit Icon ✏️) -->
<div id="edit-client-record-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 850px; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color);">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 0.5rem; border-radius: 8px;">
                    <i data-lucide="edit-3" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.2rem; font-weight: 700; color: var(--text-main);">
                        Edit Client Directory Record
                    </h3>
                    <span class="text-xs text-muted">Update client profile details, license parameters, software edition, and address.</span>
                </div>
            </div>
            <button class="btn-icon" onclick="window.closeModal('edit-client-record-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form action="index.php?page=clients&tab=directory" method="POST">
            <input type="hidden" name="action" value="update_client_directory">
            <input type="hidden" id="edit_client_db_id" name="client_db_id" value="">

            <div class="modal-body p-6 flex flex-col gap-4" style="max-height: 480px; overflow-y: auto;">
                
                <div class="grid grid-3 gap-3">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Party Name *</label>
                        <input type="text" id="edit_party_name" name="party_name" required class="form-control text-xs">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Customer ID *</label>
                        <input type="text" id="edit_customer_id" name="customer_id" required class="form-control text-xs font-mono">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">S/W Type</label>
                        <input type="text" id="edit_sw_type" name="sw_type" value="Marg" class="form-control text-xs">
                    </div>
                </div>

                <div class="grid grid-3 gap-3">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Reg Mobile</label>
                        <input type="text" id="edit_mobile" name="mobile" class="form-control text-xs font-mono">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Reg Email</label>
                        <input type="email" id="edit_email" name="email" class="form-control text-xs font-mono">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Contact Person</label>
                        <input type="text" id="edit_contact_person" name="contact_person" class="form-control text-xs">
                    </div>
                </div>

                <div class="grid grid-3 gap-3">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Software Type (Edition)</label>
                        <input type="text" id="edit_software_type" name="software_type" placeholder="e.g. Marg ERP Silver" class="form-control text-xs">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">User Type</label>
                        <select id="edit_user_type" name="user_type" class="form-control text-xs">
                            <option value="Single User">Single User</option>
                            <option value="Multi User">Multi User</option>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">No. of Users</label>
                        <input type="number" id="edit_no_of_users" name="no_of_users" min="1" value="1" class="form-control text-xs">
                    </div>
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-bold">Registered Address</label>
                    <textarea id="edit_address" name="address" rows="2" class="form-control text-xs"></textarea>
                </div>

                <div class="grid grid-3 gap-3">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">City</label>
                        <input type="text" id="edit_city" name="city" class="form-control text-xs">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">State</label>
                        <input type="text" id="edit_state" name="state" class="form-control text-xs">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Online Zip Code</label>
                        <input type="text" id="edit_online_zip_code" name="online_zip_code" class="form-control text-xs font-mono">
                    </div>
                </div>

                <div class="grid grid-3 gap-3">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Software Trade</label>
                        <input type="text" id="edit_software_trade" name="software_trade" placeholder="e.g. Pharmaceutical & Chemicals" class="form-control text-xs">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Total Contract Amount (₹)</label>
                        <input type="number" step="0.01" id="edit_total_amount" name="total_amount" class="form-control text-xs font-mono">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Party Status</label>
                        <select id="edit_party_status" name="party_status" class="form-control text-xs">
                            <option value="Running">Running</option>
                            <option value="Expired">Expired</option>
                            <option value="Deactive">Deactive</option>
                            <option value="Suspended">Suspended</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-3 gap-3">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Due On</label>
                        <input type="date" id="edit_due_on" name="due_on" class="form-control text-xs font-mono">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Act On</label>
                        <input type="date" id="edit_act_on" name="act_on" class="form-control text-xs font-mono">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold">Software Hit Date</label>
                        <input type="date" id="edit_software_hit_date" name="software_hit_date" class="form-control text-xs font-mono">
                    </div>
                </div>

            </div>

            <div class="modal-footer p-4 flex justify-end gap-2" style="background-color: var(--border-card); border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('edit-client-record-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm flex align-center gap-2">
                    <i data-lucide="check" style="width: 16px; height: 16px;"></i>
                    <span>Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Column Header Preferences JavaScript
const defaultDirVisibleCols = [
    'col-dir-sno', 'col-dir-sw-type', 'col-dir-customer-id', 'col-dir-party-name', 
    'col-dir-address', 'col-dir-mobile', 'col-dir-email', 'col-dir-user-type', 
    'col-dir-software-type', 'col-dir-contact-person', 'col-dir-party-status', 
    'col-dir-city', 'col-dir-software-trade', 'col-dir-total-amount', 'col-dir-actions'
];

const allDirCols = [
    'col-dir-sno', 'col-dir-sw-type', 'col-dir-customer-id', 'col-dir-subpartner-code', 
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

    // Populate Left Card: Licence Info
    document.getElementById('info_licence_no').innerText = client.customer_id || '-';
    document.getElementById('info_company_name').innerText = client.party_name || '-';
    document.getElementById('info_email').innerText = client.email || '-';
    document.getElementById('info_mobile').innerText = client.mobile || '-';
    document.getElementById('info_address1').innerText = client.address || '-';
    document.getElementById('info_address2').innerText = (client.city ? client.city + ', ' : '') + (client.state || '') + (client.online_zip_code ? ' - ' + client.online_zip_code : '');
    document.getElementById('info_address3').innerText = 'INDIA';
    document.getElementById('info_software_type').innerText = client.software_type || 'Marg ERP Silver';
    document.getElementById('info_renewal_date').innerText = client.due_on || client.act_on || 'N/A';
    document.getElementById('info_last_hit').innerText = client.software_hit_date || 'N/A';

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
