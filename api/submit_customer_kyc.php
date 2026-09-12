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
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

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

if (empty($password) || strlen($password) < 6) {
    $errors[] = "Password must be at least 6 characters long.";
} elseif ($password !== $confirm_password) {
    $errors[] = "Password and Confirm Password do not match.";
}

// 4. Server-Side Document Validation & Processing
$allowed_mimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'application/pdf'];
$allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

$pan_doc_path = null;
$aadhaar_doc_path = null;
$udyam_doc_path = null;
$gstin_doc_path = null;

$has_pan = !empty($pan_number) || (isset($_FILES['pan_doc']) && $_FILES['pan_doc']['error'] === UPLOAD_ERR_OK);
$has_aadhaar = !empty($aadhaar_number) || (isset($_FILES['aadhaar_doc']) && $_FILES['aadhaar_doc']['error'] === UPLOAD_ERR_OK);
$has_udyam = !empty($udyam_number) || (isset($_FILES['udyam_doc']) && $_FILES['udyam_doc']['error'] === UPLOAD_ERR_OK);
$has_gstin = !empty($gstin_number) || (isset($_FILES['gstin_doc']) && $_FILES['gstin_doc']['error'] === UPLOAD_ERR_OK);

if ($registration_type === 'registered') {
    // Registered Business (GST): ONLY GSTIN Registration Certificate Details are MANDATORY
    if (empty($gstin_number) || !preg_match($GOV_DOC_PATTERNS['gstin'], $gstin_number) || (GOV_STRICT_CHECKSUM && !validateGSTINChecksum($gstin_number))) {
        $errors[] = "For Registered Business, a valid 15-character GSTIN Number is mandatory.";
    }
    if (!isset($_FILES['gstin_doc']) || $_FILES['gstin_doc']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "For Registered Business, GST Registration Certificate copy must be uploaded.";
    }
} else {
    // Unregistered Business: At least ONE document (PAN, Aadhaar, OR UDYAM) is MANDATORY
    $unreg_docs_count = 0;
    if (!empty($pan_number) && isset($_FILES['pan_doc']) && $_FILES['pan_doc']['error'] === UPLOAD_ERR_OK) {
        $unreg_docs_count++;
    }
    if (!empty($aadhaar_number) && isset($_FILES['aadhaar_doc']) && $_FILES['aadhaar_doc']['error'] === UPLOAD_ERR_OK) {
        $unreg_docs_count++;
    }
    if (!empty($udyam_number) && isset($_FILES['udyam_doc']) && $_FILES['udyam_doc']['error'] === UPLOAD_ERR_OK) {
        $unreg_docs_count++;
    }

    if ($unreg_docs_count === 0) {
        $errors[] = "For Unregistered Business, at least one complete document (PAN Card, Aadhaar Card, or UDYAM Certificate) with number and document upload is mandatory.";
    }
}

// Validate individual documents if provided
// 1. PAN Card
if ($has_pan) {
    if (empty($pan_number) || !preg_match($GOV_DOC_PATTERNS['pan'], $pan_number)) {
        $errors[] = "Please provide a valid 10-character PAN Card Number.";
    }
    if (!isset($_FILES['pan_doc']) || $_FILES['pan_doc']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please upload a copy of your PAN Card.";
    } else {
        $panUpload = secureFileUpload($_FILES['pan_doc'], 'kyc_docs', $allowed_mimes, $allowed_exts);
        if (!$panUpload['success']) {
            $errors[] = "PAN Card Upload Error: " . $panUpload['error'];
        } else {
            $pan_doc_path = $panUpload['file_path'];
        }
    }
}

// 2. Aadhaar Card
if ($has_aadhaar) {
    if (empty($aadhaar_number) || strlen($aadhaar_number) !== 12 || (GOV_STRICT_CHECKSUM && !validateAadhaarVerhoeff($aadhaar_number))) {
        $errors[] = "Please provide a valid 12-digit Aadhaar Card Number.";
    }
    if (!isset($_FILES['aadhaar_doc']) || $_FILES['aadhaar_doc']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please upload a copy of your Aadhaar Card.";
    } else {
        $aadhaarUpload = secureFileUpload($_FILES['aadhaar_doc'], 'kyc_docs', $allowed_mimes, $allowed_exts);
        if (!$aadhaarUpload['success']) {
            $errors[] = "Aadhaar Card Upload Error: " . $aadhaarUpload['error'];
        } else {
            $aadhaar_doc_path = $aadhaarUpload['file_path'];
        }
    }
}

// 3. UDYAM Registration Certificate
if ($has_udyam) {
    if (empty($udyam_number) || !preg_match($GOV_DOC_PATTERNS['udyam'], $udyam_number)) {
        $errors[] = "Please provide a valid UDYAM Registration Certificate Number (e.g. UDYAM-UP-00-0000000).";
    }
    if (!isset($_FILES['udyam_doc']) || $_FILES['udyam_doc']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please upload a copy of your UDYAM Certificate.";
    } else {
        $udyamUpload = secureFileUpload($_FILES['udyam_doc'], 'kyc_docs', $allowed_mimes, $allowed_exts);
        if (!$udyamUpload['success']) {
            $errors[] = "UDYAM Certificate Upload Error: " . $udyamUpload['error'];
        } else {
            $udyam_doc_path = $udyamUpload['file_path'];
        }
    }
}

// 4. GSTIN Registration Certificate
if ($registration_type === 'registered' && $has_gstin) {
    if (isset($_FILES['gstin_doc']) && $_FILES['gstin_doc']['error'] === UPLOAD_ERR_OK) {
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
    // Generate unique KYC Reference ID for tracking
    $lead_id = 'KYC-' . rand(1000, 9999);

    // Initial KYC Status determination based on government verification results
    $kyc_status = ($pan_verified && $aadhaar_verified && $udyam_verified && ($registration_type !== 'registered' || $gstin_verified)) ? 'Verified' : 'Pending';

    // Hash user-chosen login password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO customer_kyc_details (
        lead_id, full_name, email, password, phone, firm_name, registration_type,
        pan_number, pan_doc_path, pan_verified, pan_api_response,
        aadhaar_number, aadhaar_doc_path, aadhaar_verified, aadhaar_api_response,
        udyam_number, udyam_doc_path, udyam_verified, udyam_api_response,
        gstin_number, gstin_doc_path, gstin_verified, gstin_api_response,
        kyc_status
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?
    )");

    $stmt->execute([
        $lead_id, $full_name, $email, $password_hash, $phone, $firm_name, $registration_type,
        (!empty($pan_number) ? $pan_number : null), $pan_doc_path, $pan_verified, $pan_api_resp,
        (!empty($aadhaar_number) ? $aadhaar_number : null), $aadhaar_doc_path, $aadhaar_verified, $aadhaar_api_resp,
        (!empty($udyam_number) ? $udyam_number : null), $udyam_doc_path, $udyam_verified, $udyam_api_resp,
        ($registration_type === 'registered' && !empty($gstin_number) ? $gstin_number : null),
        ($registration_type === 'registered' ? $gstin_doc_path : null),
        ($registration_type === 'registered' ? $gstin_verified : 0),
        ($registration_type === 'registered' ? $gstin_api_resp : null),
        $kyc_status
    ]);

    $kyc_record_id = $pdo->lastInsertId();

    // 7. Auto-create / Update User Account in `users` with 'Pending Approval' status
    // Client CANNOT login until Administrator reviews and approves their account
    try {
        $stmtUserCheck = $pdo->prepare("SELECT id, status FROM users WHERE LOWER(email) = ?");
        $stmtUserCheck->execute([$email]);
        $existingUser = $stmtUserCheck->fetch();

        if (!$existingUser) {
            $stmtInsUser = $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'Tenant Admin', 'Pending Approval')");
            $stmtInsUser->execute([$full_name, $email, $password_hash]);
        } elseif (in_array($existingUser['status'], ['Pending Approval', 'Unverified', 'Pending'])) {
            $stmtUpUser = $pdo->prepare("UPDATE users SET name = ?, password = ?, role = 'Tenant Admin', status = 'Pending Approval' WHERE id = ?");
            $stmtUpUser->execute([$full_name, $password_hash, $existingUser['id']]);
        }
    } catch (\PDOException $exUser) {}

    // 8. KYC Record strictly isolated in customer_kyc_details (Does NOT insert into leads table)

    // 9. Create System Notification for Admin
    try {
        $stmtNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', ?, ?, ?, 'info')");
        $stmtNotif->execute([
            'New Customer Registration (Under Review)',
            "{$firm_name} ({$full_name}) has registered with credentials & KYC documents.",
            'index.php?page=customer_kyc'
        ]);
    } catch (PDOException $exNotif) {}

    echo json_encode([
        'success' => true,
        'message' => 'Registration Completed Successfully! Your account details and documents are Under Review.',
        'kyc_id' => $kyc_record_id,
        'lead_id' => $lead_id,
        'status' => 'Under Review'
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database Error: ' . $e->getMessage()
    ]);
}
