<?php
/**
 * Friendly AI Solution - 1-Click Excel Last Follow Up Sync Tool for Existing Leads
 * Syncs 'Last Follow Up' column from Excel spreadsheet directly into 'followups' DB table.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../modules/leads/import.php'; // Reuse parseXLSX & parseImportedFollowupDate

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST request expected with excel_file.']);
    exit;
}

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Please upload a valid Excel (.xlsx) or CSV (.csv) file.']);
    exit;
}

$file_tmp = $_FILES['excel_file']['tmp_name'];
$file_name = $_FILES['excel_file']['name'];
$ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

$rows_data = [];
if ($ext === 'xlsx') {
    $rows_data = parseXLSX($file_tmp);
} elseif ($ext === 'csv') {
    if (($handle = fopen($file_tmp, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
            $rows_data[] = $data;
        }
        fclose($handle);
    }
}

if (empty($rows_data)) {
    echo json_encode(['success' => false, 'error' => 'Spreadsheet file is empty or could not be parsed.']);
    exit;
}

$header = array_shift($rows_data);

// Column aliases
$col_phone = -1;
$col_followup = -1;

foreach ($header as $idx => $header_val) {
    $sanitized = preg_replace('/[^a-z0-9]/', '', strtolower($header_val));
    if (in_array($sanitized, ['phone', 'contact', 'contactnumber', 'phonenumber', 'mobile'])) {
        $col_phone = $idx;
    }
    if (in_array($sanitized, ['lastfollowup', 'lastfollowupdate', 'followup', 'followupdate', 'scheduledat', 'nextfollowup', 'lastcontact', 'date'])) {
        $col_followup = $idx;
    }
}

if ($col_phone === -1 || $col_followup === -1) {
    echo json_encode([
        'success' => false,
        'error' => 'Could not detect required columns. Please ensure your Excel sheet has "Phone" and "Last Follow Up" headers.'
    ]);
    exit;
}

$synced_count = 0;
$skipped_count = 0;

if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

try {
    $stmtFindLead = $pdo->prepare("SELECT id, assigned_to FROM leads WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+91', '') LIKE ? LIMIT 1");
    $stmtInsFollowup = $pdo->prepare("INSERT INTO followups (company_id, lead_id, action_type, scheduled_at, remarks, status, assigned_to, created_at) VALUES (1, ?, 'call', ?, 'Imported from Excel (Last Followup)', ?, ?, NOW())");

    foreach ($rows_data as $row) {
        $phone = trim($row[$col_phone] ?? '');
        $raw_fup = trim($row[$col_followup] ?? '');

        if (empty($phone) || empty($raw_fup)) {
            $skipped_count++;
            continue; // Skip empty rows or empty Last Follow Up
        }

        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean_phone) < 10) {
            $skipped_count++;
            continue;
        }

        // Find existing lead
        $stmtFindLead->execute(['%' . substr($clean_phone, -10)]);
        $lead = $stmtFindLead->fetch(PDO::FETCH_ASSOC);

        if ($lead) {
            $scheduled_at = parseImportedFollowupDate($raw_fup);
            if ($scheduled_at !== null) {
                $fup_status = (strtotime($scheduled_at) <= time()) ? 'completed' : 'pending';
                $assignee = !empty($lead['assigned_to']) ? $lead['assigned_to'] : ($_SESSION['user_name'] ?? 'Admin');

                $stmtInsFollowup->execute([
                    $lead['id'],
                    $scheduled_at,
                    $fup_status,
                    $assignee
                ]);
                $synced_count++;
            } else {
                $skipped_count++;
            }
        } else {
            $skipped_count++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Successfully synced {$synced_count} followups for existing leads! ({$skipped_count} empty/unmatched rows skipped).",
        'synced_count' => $synced_count,
        'skipped_count' => $skipped_count
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
