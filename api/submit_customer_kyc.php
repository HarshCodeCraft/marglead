<?php
/**
 * Friendly AI Solution - Customer KYC & Details Submission Endpoint
 * Validates mandatory basic fields, identity documents (PAN, Aadhaar, UDYAM), 
 * conditional GSTIN document for registered entities, and saves submission to DB.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/gov_api_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method. POST expected.']);
    exit;
}

// 1. Anti-CSRF Token Validation (if token supplied)
if (isset($_POST['csrf_token']) && !verifyCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Security Error: CSRF token validation failed. Please refresh the form.']);
    exit;
}

// 2. Extract & Sanitize Basic Details
$full_name = trim($_POST['full_name'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
$firm_name = trim($_POST['firm_name'] ?? '');
$registration_type = strtolower(trim($_POST['registration_type'] ?? 'registered'));

// Document Numbers
$pan_number = strtoupper(trim($_POST['pan_number'] ?? ''));
$aadhaar_number = preg_replace('/\D/', '', $_POST['aadhaar_number'] ?? '');
$udyam_number = strtoupper(trim($_POST['udyam_number'] ?? ''));
$gstin_number = strtoupper(trim($_POST['gstin_number'] ?? ''));

// Verification status flags
$pan_verified = intval($_POST['pan_verified'] ?? 0);
$aadhaar_verified = intval($_POST['aadhaar_verified'] ?? 0);
$udyam_verified = intval($_POST['udyam_verified'] ?? 0);
$gstin_verified = intval($_POST['gstin_verified'] ?? 0);

// Raw API payload strings
$pan_api_resp = $_POST['pan_api_response'] ?? null;
$aadhaar_api_resp = $_POST['aadhaar_api_response'] ?? null;
$udyam_api_resp = $_POST['udyam_api_response'] ?? null;
$gstin_api_resp = $_POST['gstin_api_response'] ?? null;

// 3. Server-Side Mandatory Basic Validation Checks
$errors = [];

if (empty($full_name)) {
    $errors[] = "Full Name is required.";
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "A valid Email address is required.";
}

if (empty($phone) || strlen($phone) < 10) {
    $errors[] = "A valid 10-digit Phone / Mobile number is required.";
}

if (empty($firm_name)) {
    $errors[] = "Firm / Company Name is required.";
}

// 4. Server-Side Mandatory Document Numbers Validation
if (empty($pan_number) || !preg_match($GOV_DOC_PATTERNS['pan'], $pan_number)) {
    $errors[] = "Valid 10-character PAN Card Number is mandatory.";
}

if (empty($aadhaar_number) || strlen($aadhaar_number) !== 12 || (GOV_STRICT_CHECKSUM && !validateAadhaarVerhoeff($aadhaar_number))) {
    $errors[] = "Valid 12-digit Aadhaar Card Number is mandatory.";
}

if (empty($udyam_number) || !preg_match($GOV_DOC_PATTERNS['udyam'], $udyam_number)) {
    $errors[] = "Valid UDYAM Registration Certificate Number (e.g. UDYAM-UP-00-0000000) is mandatory.";
}

// Conditional GSTIN Requirement for Registered Entities
if ($registration_type === 'registered') {
    if (empty($gstin_number) || !preg_match($GOV_DOC_PATTERNS['gstin'], $gstin_number) || (GOV_STRICT_CHECKSUM && !validateGSTINChecksum($gstin_number))) {
        $errors[] = "Registered businesses MUST provide a valid 15-character GSTIN Number.";
    }
}

// 5. Secure File Uploads Verification & Processing
$allowed_mimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'application/pdf'];
$allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

// PAN Card File Upload
if (!isset($_FILES['pan_doc']) || $_FILES['pan_doc']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = "PAN Card document file upload is mandatory.";
} else {
    $panUpload = secureFileUpload($_FILES['pan_doc'], 'kyc_docs', $allowed_mimes, $allowed_exts);
    if (!$panUpload['success']) {
        $errors[] = "PAN Card Upload Error: " . $panUpload['error'];
    } else {
        $pan_doc_path = $panUpload['file_path'];
    }
}

// Aadhaar Card File Upload
if (!isset($_FILES['aadhaar_doc']) || $_FILES['aadhaar_doc']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = "Aadhaar Card document file upload is mandatory.";
} else {
    $aadhaarUpload = secureFileUpload($_FILES['aadhaar_doc'], 'kyc_docs', $allowed_mimes, $allowed_exts);
    if (!$aadhaarUpload['success']) {
        $errors[] = "Aadhaar Card Upload Error: " . $aadhaarUpload['error'];
    } else {
        $aadhaar_doc_path = $aadhaarUpload['file_path'];
    }
}

// UDYAM Certification File Upload (Mandatory for BOTH Registered and Unregistered)
if (!isset($_FILES['udyam_doc']) || $_FILES['udyam_doc']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = "UDYAM Registration Certificate file upload is mandatory.";
} else {
    $udyamUpload = secureFileUpload($_FILES['udyam_doc'], 'kyc_docs', $allowed_mimes, $allowed_exts);
    if (!$udyamUpload['success']) {
        $errors[] = "UDYAM Certificate Upload Error: " . $udyamUpload['error'];
    } else {
        $udyam_doc_path = $udyamUpload['file_path'];
    }
}

// GSTIN Certification File Upload (Mandatory ONLY if Registered)
$gstin_doc_path = null;
if ($registration_type === 'registered') {
    if (!isset($_FILES['gstin_doc']) || $_FILES['gstin_doc']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "GST Registration Certificate file upload is mandatory for Registered businesses.";
    } else {
        $gstinUpload = secureFileUpload($_FILES['gstin_doc'], 'kyc_docs', $allowed_mimes, $allowed_exts);
        if (!$gstinUpload['success']) {
            $errors[] = "GST Certificate Upload Error: " . $gstinUpload['error'];
        } else {
            $gstin_doc_path = $gstinUpload['file_path'];
        }
    }
}

if (!empty($errors)) {
    echo json_encode([
        'success' => false,
        'error' => implode(' ', $errors)
    ]);
    exit;
}

// 6. Insert Submission into `customer_kyc_details` DB Table
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable.']);
    exit;
}

try {
    // Generate unique Lead ID for CRM integration
    $lead_id = 'LD-KYC-' . rand(1000, 9999);

    // Initial KYC Status determination based on government verification results
    $kyc_status = ($pan_verified && $aadhaar_verified && $udyam_verified && ($registration_type !== 'registered' || $gstin_verified)) ? 'Verified' : 'Pending';

    $stmt = $pdo->prepare("INSERT INTO customer_kyc_details (
        lead_id, full_name, email, phone, firm_name, registration_type,
        pan_number, pan_doc_path, pan_verified, pan_api_response,
        aadhaar_number, aadhaar_doc_path, aadhaar_verified, aadhaar_api_response,
        udyam_number, udyam_doc_path, udyam_verified, udyam_api_response,
        gstin_number, gstin_doc_path, gstin_verified, gstin_api_response,
        kyc_status
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?
    )");

    $stmt->execute([
        $lead_id, $full_name, $email, $phone, $firm_name, $registration_type,
        $pan_number, $pan_doc_path, $pan_verified, $pan_api_resp,
        $aadhaar_number, $aadhaar_doc_path, $aadhaar_verified, $aadhaar_api_resp,
        $udyam_number, $udyam_doc_path, $udyam_verified, $udyam_api_resp,
        ($registration_type === 'registered' ? $gstin_number : null),
        ($registration_type === 'registered' ? $gstin_doc_path : null),
        ($registration_type === 'registered' ? $gstin_verified : 0),
        ($registration_type === 'registered' ? $gstin_api_resp : null),
        $kyc_status
    ]);

    $kyc_record_id = $pdo->lastInsertId();

    // 7. Auto-create CRM Lead Record in `leads` table so customer appears in Marg CRM
    try {
        $stmtLead = $pdo->prepare("INSERT INTO leads (
            id, name, contact_person, company, email, phone, gst, source, status, priority, remarks
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, 'KYC Form', 'new', 'hot', ?
        ) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP");

        $remarks = "Submitted KYC Details (" . ucfirst($registration_type) . " Business). PAN: {$pan_number}, Aadhaar: {$aadhaar_number}, UDYAM: {$udyam_number}";
        if ($registration_type === 'registered') {
            $remarks .= ", GSTIN: {$gstin_number}";
        }

        $stmtLead->execute([
            $lead_id,
            $firm_name,
            $full_name,
            $firm_name,
            $email,
            $phone,
            ($registration_type === 'registered' ? $gstin_number : null),
            $remarks
        ]);
    } catch (PDOException $exLead) {
        // Silently skip if lead already exists
    }

    // 8. Create System Notification for Admin
    try {
        $stmtNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', ?, ?, ?, 'info')");
        $stmtNotif->execute([
            'New Customer KYC Submission',
            "{$firm_name} ({$full_name}) has submitted customer KYC details & documents.",
            'index.php?page=customer_kyc'
        ]);
    } catch (PDOException $exNotif) {}

    echo json_encode([
        'success' => true,
        'message' => 'Customer Details & Verification Documents submitted successfully!',
        'kyc_id' => $kyc_record_id,
        'lead_id' => $lead_id,
        'status' => $kyc_status
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database Error: ' . $e->getMessage()
    ]);
}
