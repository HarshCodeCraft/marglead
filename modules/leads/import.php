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

        // 2. Locate worksheet XML file (sheet1.xml, sheet0.xml, or first xml file in xl/worksheets/)
        $sheetData = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetData) {
            $sheetData = $zip->getFromName('xl/worksheets/sheet0.xml');
        }
        if (!$sheetData) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat && str_starts_with($stat['name'], 'xl/worksheets/') && str_ends_with($stat['name'], '.xml')) {
                    $sheetData = $zip->getFromName($stat['name']);
                    if ($sheetData) break;
                }
            }
        }

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
            $autoColIdx = 0;
            foreach ($row->c as $c) {
                $coord = (string)($c['r'] ?? '');
                $colLetter = preg_replace('/[0-9]/', '', $coord);
                
                if (!empty($colLetter)) {
                    $colIdx = 0;
                    $len = strlen($colLetter);
                    for ($i = 0; $i < $len; $i++) {
                        $colIdx = $colIdx * 26 + (ord(strtoupper($colLetter[$i])) - 64);
                    }
                    $colIdx = $colIdx - 1;
                } else {
                    $colIdx = $autoColIdx;
                }
                $autoColIdx = max($autoColIdx + 1, $colIdx + 1);

                $val = '';
                $type = (string)($c['t'] ?? '');
                
                if ($type === 's') {
                    if (isset($c->v)) {
                        $sIdx = (int)$c->v;
                        $val = $sharedStrings[$sIdx] ?? '';
                    }
                } elseif ($type === 'inlineStr') {
                    if (isset($c->is->t)) {
                        $val = (string)$c->is->t;
                    }
                } else {
                    if (isset($c->v)) {
                        $val = (string)$c->v;
                    }
                }
                
                $rowData[$colIdx] = trim($val);
            }
            
            if (!empty($rowData)) {
                $maxIdx = max(array_keys($rowData));
                $normalizedRow = [];
                for ($i = 0; $i <= $maxIdx; $i++) {
                    $normalizedRow[$i] = $rowData[$i] ?? '';
                }
                $rows[] = $normalizedRow;
            }
        }
        
        $zip->close();
        return $rows;
    }
    return false;
}

// Helper: Filter out title/banner rows above real table headers
function cleanSpreadsheetRows($rows) {
    if (empty($rows)) return [];
    
    $headerRowIndex = 0;
    foreach ($rows as $idx => $r) {
        $rowStr = strtolower(implode(' ', (array)$r));
        if (str_contains($rowStr, 'phone') || str_contains($rowStr, 'contact') || str_contains($rowStr, 'mobile') || str_contains($rowStr, 'name') || str_contains($rowStr, 'email')) {
            $headerRowIndex = $idx;
            break;
        }
    }
    
    return array_values(array_slice($rows, $headerRowIndex));
}

// Universal Multi-Format Spreadsheet Parser (XLSX, XLS XML 2003, HTML Table, TSV, CSV)
function parseUniversalSpreadsheet($filename) {
    // Strategy 1: Try Native ZipArchive XLSX Parser
    $rows = parseXLSX($filename);
    if ($rows !== false && !empty($rows) && count($rows) > 0) {
        return cleanSpreadsheetRows($rows);
    }
    
    // Read raw file content to test XML 2003, HTML Table, or Delimited text
    $rawContent = @file_get_contents($filename);
    if (empty($rawContent)) return false;

    // Strategy 2: Check XML Spreadsheet 2003 format (common in Microsoft Excel exports)
    if (str_contains($rawContent, '<Workbook') || str_contains($rawContent, 'urn:schemas-microsoft-com:office:spreadsheet')) {
        $xml = @simplexml_load_string($rawContent);
        if ($xml) {
            $rows = [];
            $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
            $xmlRows = $xml->xpath('//ss:Table/ss:Row');
            if (empty($xmlRows)) {
                $xmlRows = $xml->xpath('//Table/Row');
            }
            foreach ($xmlRows as $r) {
                $rowData = [];
                $cells = $r->children('urn:schemas-microsoft-com:office:spreadsheet')->Cell;
                if (empty($cells)) $cells = $r->Cell;
                foreach ($cells as $c) {
                    $val = (string)($c->Data ?? $c->children('urn:schemas-microsoft-com:office:spreadsheet')->Data ?? '');
                    $rowData[] = trim($val);
                }
                if (!empty(array_filter($rowData))) {
                    $rows[] = $rowData;
                }
            }
            if (!empty($rows)) return cleanSpreadsheetRows($rows);
        }
    }

    // Strategy 3: Check HTML Table format (common when saving HTML reports as .xls / .xlsx)
    if (str_contains(strtolower($rawContent), '<table')) {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $rawContent);
        $trList = $doc->getElementsByTagName('tr');
        $rows = [];
        foreach ($trList as $tr) {
            $rowData = [];
            $tdList = $tr->getElementsByTagName('td');
            if ($tdList->length === 0) {
                $tdList = $tr->getElementsByTagName('th');
            }
            foreach ($tdList as $td) {
                $rowData[] = trim($td->nodeValue);
            }
            if (!empty(array_filter($rowData))) {
                $rows[] = $rowData;
            }
        }
        if (!empty($rows)) return cleanSpreadsheetRows($rows);
    }

    // Strategy 4: Delimited Text Parser (TSV, CSV, Semicolon, Pipe)
    $firstLine = '';
    if (($handle = fopen($filename, "r")) !== FALSE) {
        $firstLine = fgets($handle);
        fclose($handle);
    }
    
    $delimiter = "\t";
    if (!empty($firstLine)) {
        $delimiters = ["\t", ",", ";", "|"];
        $counts = [];
        foreach ($delimiters as $delim) {
            $counts[$delim] = substr_count($firstLine, $delim);
        }
        arsort($counts);
        $detected = key($counts);
        if ($counts[$detected] > 0) {
            $delimiter = $detected;
        }
    }

    $rows = [];
    if (($handle = fopen($filename, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 0, $delimiter, '"')) !== FALSE) {
            $cleanRow = array_map('trim', $data);
            if (!empty(array_filter($cleanRow))) {
                $rows[] = $cleanRow;
            }
        }
        fclose($handle);
    }
    
    return !empty($rows) ? cleanSpreadsheetRows($rows) : false;
}

// Global Helpers for Lead Import
if (!function_exists('sanitizeHeaderName')) {
    function sanitizeHeaderName($str) {
        return preg_replace('/[^a-z0-9]/', '', strtolower($str));
    }
}

if (!function_exists('parseImportedFollowupDate')) {
    function parseImportedFollowupDate($val) {
        $val = trim((string)$val);
        if (empty($val)) return null;

        // 1. Numeric Excel Serial Date Check
        if (is_numeric($val) && $val > 30000 && $val < 60000) {
            $unixTimestamp = intval(($val - 25569) * 86400);
            return date('Y-m-d H:i:s', $unixTimestamp);
        }

        // 2. Standard string date parsing (convert / to -)
        $cleanVal = str_replace('/', '-', $val);
        $ts = strtotime($cleanVal);
        if ($ts !== false && $ts > 0) {
            $hasTime = (date('H:i:s', $ts) !== '00:00:00');
            return $hasTime ? date('Y-m-d H:i:s', $ts) : date('Y-m-d 10:00:00', $ts);
        }

        return null;
    }
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
        fputcsv($out, ['Name', 'Phone', 'Email', 'Company', 'Assigned To', 'Last Follow Up']);
        fputcsv($out, ['Amit Sharma', '919454883552', 'amit.sharma@apexpharma.com', 'Apex Pharma Solutions', 'AJAY RATHOUR', '2026-08-25 10:00:00']);
        fputcsv($out, ['Dr. Satish Verma', '919998877766', 'drverma@diagnostic.in', 'Dr. Verma Diagnostic Clinic', 'HARSH SAINI', '']);
        fputcsv($out, ['Rajesh Gupta', '919123456789', 'rgupta@metrochem.org', 'Metro Chemicals & Co.', 'MOIN KHAN', '2026-08-28 14:30:00']);
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
        
        // Universal multi-strategy parser (XLSX Zip, XML 2003, HTML Table, TSV, CSV)
        $rows_data = parseUniversalSpreadsheet($file_tmp);
        
        if (empty($rows_data)) {
            $message = "Failed to parse spreadsheet file structure or no valid data rows found.";
            $message_type = "danger";
        }
        
        // Map raw row data to database import models
        if (!empty($rows_data) && empty($message)) {
            // Shift header row
            $header = array_shift($rows_data);
            
            // Check if shifted header was actually a data row (e.g. contains a 10-digit phone number and no header labels)
            $headerStr = implode(' ', (array)$header);
            $headerDigits = preg_replace('/[^0-9]/', '', $headerStr);
            if (strlen($headerDigits) >= 10 && !preg_match('/(phone|contact|mobile|name|email|business|company)/i', $headerStr)) {
                // Header was actually Data Row #1! Unshift it back so Row #1 is preserved.
                array_unshift($rows_data, $header);
                $header = [];
            }
            
            // NOTE: Marg ERP exports 'Name' = Firm/Business Name, NOT a person name.
            // So 'name', 'partyname', 'clientname', 'fullname' all map to 'company' DB field.
            $field_mappings = [
                'created_on'        => ['createdon', 'createdat', 'datecreated', 'createddate'],
                'company'           => ['name', 'partyname', 'clientname', 'fullname', 'company', 'companyname', 'contactbusiness', 'firmname', 'organization', 'firm', 'shopname', 'businessname'],
                'phone'             => ['contact', 'phone', 'contactnumber', 'phonenumber', 'mobile', 'mobilephone', 'mobileno', 'mobilenumber', 'phoneno', 'contactno', 'cell', 'whatsapp'],
                'email'             => ['email', 'emailaddress', 'mail', 'emailid'],
                'group_stage'       => ['group', 'leadgroup', 'stage', 'groupstatus'],
                'last_followup_text'=> ['lastfollowup', 'contactlastfollowup', 'lastfollowuptext'],
                'last_followup_date'=> ['lastfollowupdate', 'contactfollowupdate'],
                'reminder_date'     => ['reminder', 'reminderdate'],
                'reminder_time'     => ['remindertime', 'remindtime'],
                'address'           => ['address', 'pincode', 'location', 'city', 'district', 'area', 'state'],
                'source'            => ['source', 'leadsource', 'channel'],
                'enq_for'           => ['enqfor', 'product', 'enquiryfor', 'products', 'item', 'module'],
                'contact_person'    => ['contactperson', 'person', 'contactpersonname', 'personname'],
                'remarks'           => ['remark', 'remarks', 'note', 'notes', 'comment'],
                'assigned_to'       => ['assignedto', 'assigned', 'operator', 'assignee', 'representative', 'executive'],
                'tags'              => ['tags', 'tag', 'category'],
            ];

            $col_indices = [
                'company'           => -1,
                'contact_person'    => -1,
                'phone'             => -1,
                'email'             => -1,
                'assigned_to'       => -1,
                'address'           => -1,
                'source'            => -1,
                'enq_for'           => -1,
                'group_stage'       => -1,
                'remarks'           => -1,
                'tags'              => -1,
                'created_on'        => -1,
                'last_followup_text'=> -1,
                'last_followup_date'=> -1,
                'reminder_date'     => -1,
                'reminder_time'     => -1,
            ];
            
            foreach ($header as $idx => $header_val) {
                $sanitized = sanitizeHeaderName($header_val);
                if (empty($sanitized)) continue;
                
                foreach ($field_mappings as $field_key => $aliases) {
                    if (in_array($sanitized, $aliases) && $col_indices[$field_key] === -1) {
                        $col_indices[$field_key] = $idx;
                        break;
                    }
                }
            }

            // Fallback 1: Smart Phone Column Auto-Detection if header didn't match exact alias
            if ($col_indices['phone'] === -1 && !empty($rows_data)) {
                $phone_scores = [];
                foreach (array_slice($rows_data, 0, 10) as $sampleRow) {
                    foreach ((array)$sampleRow as $cIdx => $cVal) {
                        $digits = preg_replace('/[^0-9]/', '', (string)$cVal);
                        if (strlen($digits) >= 10 && strlen($digits) <= 13) {
                            $phone_scores[$cIdx] = ($phone_scores[$cIdx] ?? 0) + 1;
                        }
                    }
                }
                if (!empty($phone_scores)) {
                    arsort($phone_scores);
                    $col_indices['phone'] = key($phone_scores);
                }
            }

            // Fallback 2: Smart Business / Name Column Auto-Detection if header didn't match exact alias
            if ($col_indices['company'] === -1 && ($col_indices['name'] ?? -1) === -1 && !empty($rows_data)) {
                foreach (array_slice($rows_data, 0, 5) as $sampleRow) {
                    foreach ((array)$sampleRow as $cIdx => $cVal) {
                        if ($cIdx == $col_indices['phone']) continue;
                        $sVal = trim((string)$cVal);
                        if (strlen($sVal) > 2 && !is_numeric($sVal) && !str_contains($sVal, '@')) {
                            $col_indices['company'] = $cIdx;
                            break 2;
                        }
                    }
                }
            }
            
            $row_idx = 1;
            foreach ($rows_data as $row) {
                $row_idx++;
                
                $raw_phone   = trim($col_indices['phone']            >= 0 ? ($row[$col_indices['phone']]            ?? '') : '');
                $raw_email   = trim($col_indices['email']            >= 0 ? ($row[$col_indices['email']]            ?? '') : '');
                $raw_company = trim($col_indices['company']          >= 0 ? ($row[$col_indices['company']]          ?? '') : '');
                $assigned_to = trim($col_indices['assigned_to']      >= 0 ? ($row[$col_indices['assigned_to']]      ?? '') : '');
                $address     = trim($col_indices['address']          >= 0 ? ($row[$col_indices['address']]          ?? '') : '');
                $source      = trim($col_indices['source']           >= 0 ? ($row[$col_indices['source']]           ?? '') : '');
                $enq_for     = trim($col_indices['enq_for']          >= 0 ? ($row[$col_indices['enq_for']]          ?? '') : '');
                $raw_contact_person = trim($col_indices['contact_person'] >= 0 ? ($row[$col_indices['contact_person']] ?? '') : '');
                $group_stage = trim($col_indices['group_stage']      >= 0 ? ($row[$col_indices['group_stage']]      ?? '') : '');
                $remarks     = trim($col_indices['remarks']          >= 0 ? ($row[$col_indices['remarks']]          ?? '') : '');
                $tags        = trim($col_indices['tags']             >= 0 ? ($row[$col_indices['tags']]             ?? '') : '');
                $created_on  = trim($col_indices['created_on']       >= 0 ? ($row[$col_indices['created_on']]       ?? '') : '');
                $last_followup_text = trim($col_indices['last_followup_text'] >= 0 ? ($row[$col_indices['last_followup_text']] ?? '') : '');
                $last_followup_date = trim($col_indices['last_followup_date'] >= 0 ? ($row[$col_indices['last_followup_date']] ?? '') : '');
                $reminder_date = trim($col_indices['reminder_date']  >= 0 ? ($row[$col_indices['reminder_date']]    ?? '') : '');
                $reminder_time = trim($col_indices['reminder_time']  >= 0 ? ($row[$col_indices['reminder_time']]    ?? '') : '');
                
                // Clean up text values (remove quotes, extra commas, NA placeholders)
                $clean_val = function($str) {
                    $s = trim((string)$str);
                    $s = str_replace(['"', "'"], '', $s);
                    $s = trim($s, ", \t\n\r\0\x0B");
                    return in_array(strtolower($s), ['na', '-', '—', 'n/a', 'null', 'none']) ? '' : $s;
                };

                // Clean up phone number (strip Excel .00 float suffix and keep clean digits)
                if (str_contains($raw_phone, '.')) {
                    $raw_phone = explode('.', $raw_phone)[0];
                }
                $phone = preg_replace('/[^0-9]/', '', $raw_phone);

                // company = Marg ERP 'Name' column (firm/business name)
                $company        = $clean_val($raw_company);
                $contact_person = $clean_val($raw_contact_person);
                $enq_for        = $clean_val($enq_for);
                $address        = $clean_val($address);
                $source         = $clean_val($source);
                $tags           = $clean_val($tags);
                $remarks        = $clean_val($remarks);
                $assigned_to    = $clean_val($assigned_to);
                $group_stage    = $clean_val($group_stage);
                
                // Executive email resolution check: If email matches executive/admin email, do not save as client email
                $email = $clean_val($raw_email);
                if (!empty($email) && (str_contains(strtolower($email), 'poornimabajpai') || str_contains(strtolower($email), 'harshsaini') || str_contains(strtolower($email), 'admin@marglead'))) {
                    if (empty($assigned_to)) {
                        $assigned_to = $email;
                    }
                    $email = ''; // Clear client email field
                }

                // Display name = company first, then contact person, then phone fallback
                if (!empty($company)) {
                    $name = $company;
                } elseif (!empty($contact_person)) {
                    $name = $contact_person;
                } else {
                    $name = !empty($phone) ? 'Lead (' . $phone . ')' : 'New Lead';
                }

                // Determine Lead Status & Pipeline Stage from Group
                $lead_stage_status = 'new';
                if (!empty($group_stage)) {
                    $gLower = strtolower($group_stage);
                    if (str_contains($gLower, 'demo')) {
                        $lead_stage_status = 'interested';
                    } elseif (str_contains($gLower, 'follow')) {
                        $lead_stage_status = 'contacted';
                    } elseif (str_contains($gLower, 'not required') || str_contains($gLower, 'lost') || str_contains($gLower, 'not int')) {
                        $lead_stage_status = 'dropped';
                    } elseif (str_contains($gLower, 'installation')) {
                        $lead_stage_status = 'won';
                    } elseif (str_contains($gLower, 'fresh')) {
                        $lead_stage_status = 'new';
                    }
                }
                
                // Determine effective followup date
                $effective_fup_date = '';
                if (!empty($reminder_date)) {
                    $effective_fup_date = $reminder_date . (!empty($reminder_time) ? ' ' . $reminder_time : '');
                } elseif (!empty($last_followup_date)) {
                    $effective_fup_date = $last_followup_date;
                }
                
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
                    'group_stage' => $group_stage,
                    'tags' => $tags,
                    'created_on' => $created_on,
                    'last_followup_text' => $last_followup_text,
                    'effective_fup_date' => $effective_fup_date,
                    'duplicate_id' => $duplicate_lead_id,
                    'lead_stage_status' => $lead_stage_status,
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
            
            // Build Users Email & Name Lookup Cache for Executive Resolution
            $user_email_map = [];
            $user_name_map = [];
            $super_admin_name = 'Admin';

            try {
                $uStmt = $pdo->query("SELECT name, email, role FROM users WHERE status = 'Active'");
                while ($u = $uStmt->fetch(PDO::FETCH_ASSOC)) {
                    $uName = trim($u['name']);
                    $uEmail = strtolower(trim($u['email']));
                    if (!empty($uEmail)) {
                        $user_email_map[$uEmail] = $uName;
                    }
                    if (!empty($uName)) {
                        $user_name_map[strtolower($uName)] = $uName;
                    }
                    if (in_array(strtolower($u['role']), ['admin', 'super admin', 'superadmin'])) {
                        $super_admin_name = $uName;
                    }
                }
            } catch (PDOException $exU) {}
            
            $ins = $pdo->prepare("INSERT INTO leads (id, company_id, name, company, email, phone, address, source, tags, group_stage, assigned_to, assigned_by, enq_for, contact_person, remarks, status, priority) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'warm')");
            $upd = $pdo->prepare("UPDATE leads SET name = COALESCE(?, name), company = COALESCE(?, company), email = COALESCE(?, email), address = COALESCE(?, address), source = COALESCE(?, source), tags = COALESCE(?, tags), group_stage = COALESCE(?, group_stage), assigned_to = COALESCE(?, assigned_to), assigned_by = COALESCE(?, assigned_by), enq_for = COALESCE(?, enq_for), contact_person = COALESCE(?, contact_person), remarks = COALESCE(?, remarks), status = COALESCE(?, status) WHERE id = ?");
            $log = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, 'Lead file registered via bulk spreadsheet import')");
            $generated_ids = [];
            foreach ($leads_to_import as $lead) {
                if ($lead['status'] !== 'Valid') {
                    continue; // Skip invalid records
                }
                
                $finalCompany = !empty($lead['company']) ? $lead['company'] : (!empty($lead['name']) ? $lead['name'] : null);
                
                // Resolve Executive Assignment: Match by Email -> Name (Supports multiple employees)
                $rawAssignee = strtolower(trim($lead['assigned_to'] ?? ''));
                $finalAssignee = null;
                if (!empty($rawAssignee)) {
                    $parts = explode(',', $rawAssignee);
                    $resolvedParts = [];
                    foreach ($parts as $part) {
                        $p = strtolower(trim($part));
                        if (isset($user_email_map[$p])) {
                            $resolvedParts[] = $user_email_map[$p];
                        } elseif (isset($user_name_map[$p])) {
                            $resolvedParts[] = $user_name_map[$p];
                        } elseif (!empty($p)) {
                            $resolvedParts[] = trim($part);
                        }
                    }
                    if (!empty($resolvedParts)) {
                        $finalAssignee = implode(', ', array_unique($resolvedParts));
                    }
                }
                
                // If not found in assigned_to column, check if lead email matches an executive
                if (empty($finalAssignee) && !empty($lead['email'])) {
                    $leadEmail = strtolower(trim($lead['email']));
                    if (isset($user_email_map[$leadEmail])) {
                        $finalAssignee = $user_email_map[$leadEmail];
                    }
                }
                
                $lead_stage_status = $lead['lead_stage_status'] ?? 'new';

                if (!empty($lead['duplicate_id'])) {
                    $assigned_by = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
                    // Update matching profile (passing null for empty fields so COALESCE keeps existing database values)
                    $upd->execute([
                        !empty($lead['name']) ? $lead['name'] : null,
                        $finalCompany ?: null,
                        !empty($lead['email']) ? $lead['email'] : null,
                        !empty($lead['address']) ? $lead['address'] : null,
                        !empty($lead['source']) ? $lead['source'] : 'Imported',
                        !empty($lead['tags']) ? $lead['tags'] : null,
                        !empty($lead['group_stage']) ? $lead['group_stage'] : null,
                        $finalAssignee ?: null,
                        $assigned_by,
                        !empty($lead['enq_for']) ? $lead['enq_for'] : null,
                        !empty($lead['contact_person']) ? $lead['contact_person'] : null,
                        !empty($lead['remarks']) ? $lead['remarks'] : null,
                        $lead_stage_status ?: null,
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
                        $lead['email'] ?: null,
                        $lead['phone'],
                        $lead['address'] ?: null,
                        $lead['source'] ?: 'Imported',
                        $lead['tags'] ?: null,
                        $lead['group_stage'] ?: null,
                        $finalAssignee,
                        $assigned_by,
                        $lead['enq_for'] ?: null,
                        $lead['contact_person'] ?: null,
                        $lead['remarks'] ?: null,
                        $lead_stage_status
                    ]);
                    
                    // Log to timeline
                    $log->execute([$newId, $_SESSION['user_name'] ?? 'System Admin']);
                    $inserted++;
                }

                // Automatic creation of Followup entry if Excel contains Last Follow Up / Reminder date for this lead
                $target_lead_id = !empty($lead['duplicate_id']) ? $lead['duplicate_id'] : ($newId ?? null);
                $raw_fup_date = $lead['effective_fup_date'] ?? '';
                if (!empty($target_lead_id) && !empty($raw_fup_date)) {
                    $scheduled_at = parseImportedFollowupDate($raw_fup_date);
                    if ($scheduled_at !== null) {
                        try {
                            $fup_status = (strtotime($scheduled_at) <= time()) ? 'completed' : 'pending';
                            $fup_remarks = !empty($lead['last_followup_text']) ? $lead['last_followup_text'] : (!empty($lead['remarks']) ? $lead['remarks'] : 'Imported from Excel');
                            if (!isset($ins_fup_stmt)) {
                                $ins_fup_stmt = $pdo->prepare("INSERT INTO followups (company_id, lead_id, action_type, scheduled_at, remarks, status, assigned_to, created_at) VALUES (1, ?, 'call', ?, ?, ?, ?, NOW())");
                            }
                            $ins_fup_stmt->execute([
                                $target_lead_id,
                                $scheduled_at,
                                $fup_remarks,
                                $fup_status,
                                $finalAssignee
                            ]);
                        } catch (PDOException $exFup) {}
                    }
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

<div class="lead-import-container" style="max-width: 1400px; margin: 0 auto; padding: 0 1rem;">
    <!-- Page Header -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;">Bulk Lead Import Wizard</h2>
            <p class="text-muted text-sm">Upload Excel (.xlsx, .xls) or CSV (.csv) spreadsheets to generate or merge lead opportunities.</p>
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
            <div class="card p-6 flex flex-col align-center text-center justify-center pointer" style="border: 2px dashed var(--border-color); border-radius: var(--border-radius-md); height: 260px; transition: border-color var(--transition-fast);" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border-color)'" onclick="document.getElementById('import-file-selector').click();">
                <input type="file" name="excel_file" id="import-file-selector" class="hidden" accept=".xlsx, .xls, .csv" onchange="this.form.submit();">
                <div class="flex flex-col align-center justify-center">
                    <i data-lucide="upload-cloud" class="text-muted mb-4" style="width: 48px; height: 48px; color: var(--primary);"></i>
                    <h4 class="mb-2">Choose Excel or CSV spreadsheet</h4>
                    <p class="text-xs text-muted mb-4">Click to browse or drop your Excel (.xlsx, .xls) or CSV file from your device.</p>
                    <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);">Format supported: .XLSX / .XLS / .CSV</span>
                </div>
            </div>

            <!-- Right: Guidelines panel -->
            <div class="card p-6" style="border: 1px solid var(--border-color); height: 260px; display: flex; flex-direction: column;">
                <h3 class="text-sm font-semibold mb-3">Formatting Guidelines</h3>
                <p class="text-xs text-muted mb-4">Ensure your spreadsheet matches these column configurations to avoid mapping check failures:</p>
                <ul class="flex flex-col gap-2 text-xs text-muted" style="flex: 1; overflow-y: auto;">
                    <li class="flex align-center gap-2"><i data-lucide="check" style="width: 14px; height: 14px; color: var(--success);"></i> <strong>Contact / Business</strong> (Firm Name)</li>
                    <li class="flex align-center gap-2"><i data-lucide="check" style="width: 14px; height: 14px; color: var(--success);"></i> <strong>Mobile / Phone</strong> (Unique identifier)</li>
                    <li class="flex align-center gap-2"><i data-lucide="check" style="width: 14px; height: 14px; color: var(--success);"></i> <strong>Group</strong> (Lead Stage / Followup status)</li>
                    <li class="flex align-center gap-2"><i data-lucide="check" style="width: 14px; height: 14px; color: var(--success);"></i> <strong>Contact Person, Enq For, Address, Source, Remarks, Tags</strong></li>
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
            <!-- Header Toolbar -->
            <div class="flex justify-between align-center mb-4 flex-wrap gap-3" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
                <div>
                    <h3 class="text-base font-semibold mb-1 flex align-center gap-2">
                        <span>Spreadsheet Validation & Column Preview Grid</span>
                        <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 0.75rem;">Total: <?php echo count($parsed_rows); ?> Records</span>
                    </h3>
                    <p class="text-xs text-muted">Review all parsed spreadsheet columns, contact details, assigned operators, and duplicate status below before confirming database sync.</p>
                </div>

                <!-- Page Size & Actions Control Bar -->
                <div class="flex align-center gap-3 flex-wrap">
                    <div class="flex align-center gap-2 bg-card p-1" style="border: 1px solid var(--border-color); border-radius: var(--border-radius-sm);">
                        <label for="import-page-size" class="text-xs font-semibold text-muted pl-2" style="white-space: nowrap;">View Leads:</label>
                        <select id="import-page-size" class="form-control text-xs font-semibold" style="width: auto; padding: 0.25rem 0.5rem; height: 30px;" onchange="changeImportPageSize(this.value)">
                            <option value="25">25 per page</option>
                            <option value="50" selected>50 per page</option>
                            <option value="100">100 per page</option>
                            <option value="200">200 per page</option>
                            <option value="250">250 per page</option>
                            <option value="500">500 per page</option>
                            <option value="all">All (<?php echo count($parsed_rows); ?> Rows)</option>
                        </select>
                    </div>

                    <form action="index.php?page=lead_import" method="POST" style="margin: 0;">
                        <div class="flex gap-2">
                            <button type="button" class="btn btn-secondary text-xs" style="padding: 0.5rem 1rem;" onclick="window.location.href='index.php?page=lead_import'">Cancel</button>
                            <button type="submit" name="confirm_import_action" class="btn btn-primary text-xs font-bold" style="padding: 0.5rem 1.25rem;">
                                <i data-lucide="check" style="width: 14px; height: 14px; display: inline-block; vertical-align: middle; margin-right: 4px;"></i>
                                <span>Confirm Import (<?php echo count($parsed_rows); ?>)</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table Info Bar -->
            <div class="flex justify-between align-center mb-3 text-xs text-muted">
                <div id="import-pagination-info" class="font-semibold">Showing records 1 to 50 of <?php echo count($parsed_rows); ?></div>
                <div class="flex align-center gap-1" id="import-pagination-buttons">
                    <button type="button" class="btn btn-secondary text-xs" id="btn-prev-page" onclick="navigateImportPage(-1)" style="padding: 0.2rem 0.6rem; height: 26px;">&laquo; Prev</button>
                    <span id="page-num-display" class="font-bold text-xs px-2" style="color: var(--text-color);">Page 1</span>
                    <button type="button" class="btn btn-secondary text-xs" id="btn-next-page" onclick="navigateImportPage(1)" style="padding: 0.2rem 0.6rem; height: 26px;">Next &raquo;</button>
                </div>
            </div>

            <!-- Full Grid Table -->
            <div class="table-responsive" style="max-height: 620px; overflow: auto; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm);">
                <table class="table text-xs" id="import-preview-table" style="width: 100%; white-space: nowrap;">
                    <thead style="position: sticky; top: 0; background: var(--bg-card); z-index: 10; border-bottom: 2px solid var(--border-color);">
                        <tr>
                            <th style="width: 50px; text-align: center; background: var(--bg-card);">S.No</th>
                            <th style="width: 60px; text-align: center; background: var(--bg-card);">Row #</th>
                            <th style="background: var(--bg-card);">Contact Person</th>
                            <th style="background: var(--bg-card);">Company / Firm Name</th>
                            <th style="background: var(--bg-card);">Contact Number</th>
                            <th style="background: var(--bg-card);">Email Address</th>
                            <th style="background: var(--bg-card);">Group / Stage</th>
                            <th style="background: var(--bg-card);">Enq For</th>
                            <th style="background: var(--bg-card);">Assigned To</th>
                            <th style="background: var(--bg-card);">Source</th>
                            <th style="background: var(--bg-card);">Address</th>
                            <th style="background: var(--bg-card);">Followup Date</th>
                            <th style="background: var(--bg-card);">Remarks</th>
                            <th style="background: var(--bg-card);">Tags</th>
                            <th style="background: var(--bg-card);">Duplicate Check</th>
                            <th style="background: var(--bg-card);">Status</th>
                        </tr>
                    </thead>
                    <tbody id="import-table-tbody">
                        <?php foreach ($parsed_rows as $idx => $row): 
                            $s_no = $idx + 1;
                        ?>
                            <tr class="import-row-item" data-index="<?php echo $idx; ?>" style="vertical-align: middle;">
                                <td style="text-align: center; font-weight: 700; color: var(--primary);"><?php echo $s_no; ?></td>
                                <td style="text-align: center; font-weight: 600; color: var(--text-muted);">#<?php echo $row['row_num']; ?></td>
                                <td class="font-semibold" style="color: var(--text-color);"><?php echo htmlspecialchars($row['contact_person'] ?: '---'); ?></td>
                                <td class="font-bold" style="color: var(--primary);"><?php echo htmlspecialchars($row['company'] ?: 'MARG ERP Softwares'); ?></td>
                                <td class="font-semibold"><?php echo htmlspecialchars($row['phone'] ?: '---'); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars($row['email'] ?: '---'); ?></td>
                                <td>
                                    <?php if (!empty($row['group_stage'])): ?>
                                        <span class="badge" style="--badge-bg: rgba(59, 130, 246, 0.1); --badge-color: #2563eb; font-weight: 600;"><?php echo htmlspecialchars($row['group_stage']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">---</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['enq_for'])): ?>
                                        <span class="badge" style="--badge-bg: rgba(168, 85, 247, 0.1); --badge-color: #7e22ce; font-weight: 600;"><?php echo htmlspecialchars($row['enq_for']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">---</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-semibold text-muted"><?php echo htmlspecialchars($row['assigned_to'] ?: 'Admin'); ?></td>
                                <td><span class="badge" style="--badge-bg: var(--border-card); --badge-color: var(--text-muted);"><?php echo htmlspecialchars($row['source'] ?: 'HO'); ?></span></td>
                                <td class="text-muted" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($row['address'] ?: '---'); ?></td>
                                <td>
                                    <?php if (!empty($row['effective_fup_date'])): ?>
                                        <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);"><i data-lucide="calendar" style="width: 11px; height: 11px; display:inline; margin-right:3px;"></i> <?php echo htmlspecialchars($row['effective_fup_date']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">---</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($row['remarks']); ?>"><?php echo htmlspecialchars($row['remarks'] ?: '---'); ?></td>
                                <td><?php echo htmlspecialchars($row['tags'] ?: '---'); ?></td>
                                <td>
                                    <?php if ($row['status'] !== 'Valid'): ?>
                                        <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Invalid Syntax</span>
                                    <?php elseif (!empty($row['duplicate_id'])): ?>
                                        <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);"><i data-lucide="alert-triangle" style="width: 11px; height: 11px; display:inline; margin-right:3px; vertical-align:middle;"></i> Match (<?php echo $row['duplicate_id']; ?>)</span>
                                    <?php else: ?>
                                        <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);"><i data-lucide="check-circle" style="width: 11px; height: 11px; display:inline; margin-right:3px; vertical-align:middle;"></i> Unique</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] !== 'Valid'): ?>
                                        <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);"><?php echo $row['status']; ?></span>
                                    <?php elseif (!empty($row['duplicate_id'])): ?>
                                        <span class="badge" style="--badge-bg: var(--info-light); --badge-color: var(--info);">Overwrite</span>
                                    <?php else: ?>
                                        <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Importable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Bottom Pagination Footer Bar -->
            <div class="flex justify-between align-center mt-3 text-xs text-muted">
                <div>Tip: Scroll horizontally or change rows-per-page to view all spreadsheet entries before confirming import.</div>
                <div class="flex align-center gap-2">
                    <button type="button" class="btn btn-secondary text-xs" onclick="navigateImportPage(-1)" style="padding: 0.25rem 0.75rem;">&laquo; Previous</button>
                    <span id="page-num-display-bottom" class="font-bold text-xs text-primary px-2">Page 1 of 1</span>
                    <button type="button" class="btn btn-secondary text-xs" onclick="navigateImportPage(1)" style="padding: 0.25rem 0.75rem;">Next &raquo;</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    let currentImportPage = 1;
    let importPageSize = 50;
    const totalImportRows = <?php echo !empty($parsed_rows) ? count($parsed_rows) : 0; ?>;

    function renderImportPagination() {
        const rows = document.querySelectorAll('.import-row-item');
        if (!rows.length) return;

        let effectiveSize = importPageSize === 'all' ? totalImportRows : parseInt(importPageSize);
        let totalPages = Math.ceil(totalImportRows / effectiveSize) || 1;

        if (currentImportPage > totalPages) currentImportPage = totalPages;
        if (currentImportPage < 1) currentImportPage = 1;

        let startIdx = (currentImportPage - 1) * effectiveSize;
        let endIdx = importPageSize === 'all' ? totalImportRows : startIdx + effectiveSize;
        if (endIdx > totalImportRows) endIdx = totalImportRows;

        rows.forEach((row, idx) => {
            if (idx >= startIdx && idx < endIdx) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });

        // Update info indicators
        const infoEl = document.getElementById('import-pagination-info');
        if (infoEl) {
            if (totalImportRows === 0) {
                infoEl.innerText = 'No records found.';
            } else {
                infoEl.innerText = `Showing records ${startIdx + 1} to ${endIdx} of ${totalImportRows}`;
            }
        }

        const pageDisplay1 = document.getElementById('page-num-display');
        const pageDisplay2 = document.getElementById('page-num-display-bottom');
        if (pageDisplay1) pageDisplay1.innerText = `Page ${currentImportPage} of ${totalPages}`;
        if (pageDisplay2) pageDisplay2.innerText = `Page ${currentImportPage} of ${totalPages}`;

        // Disable/enable prev and next buttons
        const prevBtns = [document.getElementById('btn-prev-page')];
        const nextBtns = [document.getElementById('btn-next-page')];
        
        prevBtns.forEach(btn => { if (btn) btn.disabled = (currentImportPage <= 1); });
        nextBtns.forEach(btn => { if (btn) btn.disabled = (currentImportPage >= totalPages); });
    }

    function changeImportPageSize(newSize) {
        importPageSize = newSize;
        currentImportPage = 1;
        renderImportPagination();
    }

    function navigateImportPage(direction) {
        currentImportPage += direction;
        renderImportPagination();
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
        
        const previewPanel = document.getElementById('import-preview-panel');
        if (previewPanel) {
            renderImportPagination();
            previewPanel.scrollIntoView({ behavior: 'smooth' });
        }
    });
</script>
