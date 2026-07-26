<?php
require_once __DIR__ . '/cors.php';

$input = getJsonInput();
$email = trim($input['email'] ?? '');
$password = trim($input['password'] ?? '');

if (empty($email) || empty($password)) {
    sendJsonResponse(['success' => false, 'message' => 'Email and password are required.'], 400);
}

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database connection failed.'], 500);
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, password, role, status, profile_photo FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Simple password check (plain or hashed)
    if ($user && ($password === $user['password'] || password_verify($password, $user['password']))) {
        if ($user['status'] !== 'Active') {
            sendJsonResponse(['success' => false, 'message' => 'Account is inactive. Contact Administrator.'], 403);
        }
        
        unset($user['password']);
        $token = bin2hex(random_bytes(24));
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => $user
        ]);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Invalid email or password.'], 401);
    }
} catch (PDOException $e) {
    sendJsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
