<?php
/**
 * Marg CRM - Client Lookup Endpoint
 * 
 * Searches client_directory, leads, and tenant_companies by License No / Phone / Customer ID.
 */

require_once __DIR__ . '/cors.php';

header('Content-Type: application/json; charset=utf-8');

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$query = trim($_GET['query'] ?? $_GET['license_no'] ?? $_POST['license_no'] ?? '');

if (empty($query)) {
    sendJsonResponse(['success' => false, 'found' => false, 'message' => 'Query is empty.'], 400);
}

try {
    // 1. Search client_directory
    $stmtCD = $pdo->prepare("SELECT * FROM client_directory WHERE customer_id = ? OR customer_id LIKE ? OR mobile LIKE ? OR email LIKE ? LIMIT 1");
    $qLike = '%' . $query . '%';
    $stmtCD->execute([$query, $qLike, $qLike, $qLike]);
    $cd = $stmtCD->fetch(PDO::FETCH_ASSOC);

    if ($cd) {
        sendJsonResponse([
            'success' => true,
            'found' => true,
            'source' => 'client_directory',
            'data' => [
                'license_no'    => $cd['customer_id'],
                'customer_name' => !empty($cd['contact_person']) ? $cd['contact_person'] : $cd['party_name'],
                'firm_name'     => !empty($cd['party_name']) ? $cd['party_name'] : $cd['company_using'],
                'phone'         => $cd['mobile'] ?? '',
                'email'         => $cd['email'] ?? '',
                'product'       => $cd['software_type'] ?? 'Marg ERP',
                'renewal_date'  => $cd['due_on'] ?? null,
                'address'       => trim(($cd['address'] ?? '') . ' ' . ($cd['city'] ?? '') . ' ' . ($cd['state'] ?? ''))
            ]
        ]);
    }

    // 2. Search leads table
    $stmtLD = $pdo->prepare("SELECT * FROM leads WHERE id = ? OR phone LIKE ? OR email LIKE ? OR name LIKE ? LIMIT 1");
    $stmtLD->execute([$query, $qLike, $qLike, $qLike]);
    $ld = $stmtLD->fetch(PDO::FETCH_ASSOC);

    if ($ld) {
        sendJsonResponse([
            'success' => true,
            'found' => true,
            'source' => 'leads',
            'data' => [
                'license_no'    => $ld['id'],
                'customer_name' => !empty($ld['contact_person']) ? $ld['contact_person'] : $ld['name'],
                'firm_name'     => $ld['company'] ?? '',
                'phone'         => $ld['phone'] ?? '',
                'email'         => $ld['email'] ?? '',
                'product'       => 'Marg ERP',
                'renewal_date'  => null,
                'address'       => trim(($ld['address'] ?? '') . ' ' . ($ld['city'] ?? '') . ' ' . ($ld['state'] ?? ''))
            ]
        ]);
    }

    // 3. Search tenant_companies
    $stmtTC = $pdo->prepare("SELECT * FROM tenant_companies WHERE company_code = ? OR phone LIKE ? OR owner_email LIKE ? LIMIT 1");
    $stmtTC->execute([$query, $qLike, $qLike]);
    $tc = $stmtTC->fetch(PDO::FETCH_ASSOC);

    if ($tc) {
        sendJsonResponse([
            'success' => true,
            'found' => true,
            'source' => 'tenant_companies',
            'data' => [
                'license_no'    => $tc['company_code'],
                'customer_name' => $tc['owner_name'],
                'firm_name'     => $tc['company_name'],
                'phone'         => $tc['phone'] ?? '',
                'email'         => $tc['owner_email'] ?? '',
                'product'       => ($tc['plan'] ?? 'Enterprise') . ' Edition',
                'renewal_date'  => $tc['expiry_date'] ?? null,
                'address'       => 'Master Tenant Account'
            ]
        ]);
    }

    sendJsonResponse([
        'success' => true,
        'found' => false,
        'message' => 'No client found matching: ' . $query
    ]);

} catch (Throwable $e) {
    sendJsonResponse([
        'success' => false,
        'found' => false,
        'message' => 'Lookup error: ' . $e->getMessage()
    ], 500);
}
