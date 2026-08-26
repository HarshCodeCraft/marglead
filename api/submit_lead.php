<?php
/**
 * Public Lead Submission Handler (Contact Us Modal)
 * Receives inquiry from Landing Page modal and inserts into `leads` table.
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';

// Accept JSON or POST form data
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input && !empty($_POST)) {
    $input = $_POST;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or payload.']);
    exit;
}

$name = trim($input['name'] ?? '');
$phone = trim($input['phone'] ?? '');
$email = trim($input['email'] ?? '');
$company = trim($input['company'] ?? '');
$remarks = trim($input['remarks'] ?? '');

// Mandatory field validation
if (empty($name) || empty($phone) || empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Name, Phone Number, and Email Address are mandatory.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// Requirements/Products Checkboxes
$productsArray = $input['products'] ?? [];
if (is_string($productsArray)) {
    $productsArray = array_map('trim', explode(',', $productsArray));
}
$productsStr = !empty($productsArray) ? implode(', ', $productsArray) : 'General Inquiry';

// Generate Lead ID LD-XXXX
$leadId = 'LD-' . rand(1000, 9999);
if (isset($pdo)) {
    $check = $pdo->prepare("SELECT id FROM leads WHERE id = ?");
    $check->execute([$leadId]);
    if ($check->fetch()) {
        $leadId = 'LD-' . rand(10000, 99999);
    }
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO leads (
            id, name, contact_person, company, email, phone, 
            city, state, address, source, priority, tags, 
            status, products, enq_for, remarks, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, NOW()
        )
    ");

    $stmt->execute([
        $leadId,
        $name,
        $name,
        $company ?: 'Individual / N/A',
        $email,
        $phone,
        'N/A',
        'N/A',
        'Website Contact Inquiry',
        'Landing Page Contact Us',
        'warm',
        'Website Lead',
        'new',
        $productsStr,
        $productsStr,
        $remarks ? "Selected Options: $productsStr | Notes: $remarks" : "Selected Options: $productsStr"
    ]);

    // Insert Timeline Audit
    $timelineStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
    $timelineStmt->execute([
        $leadId,
        'Website Visitor',
        "Inquiry submitted via Contact Us Modal for: $productsStr"
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your inquiry has been submitted successfully. Our team will contact you shortly.',
        'lead_id' => $leadId
    ]);

} catch (Exception $e) {
    error_log("Lead Submission Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Unable to save your request right now. Please try again later.'
    ]);
}
