<?php
/**
 * Test Hostinger SMTP Mail Dispatch
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

$to = $_GET['to'] ?? 'harshuharshu609@gmail.com';

$subject = 'Hostinger SMTP Live Test - Friendly AI Solution';
$body = Mailer::wrapHTMLTemplate(
    'Hostinger SMTP Verification Successful',
    'Hostinger SMTP Verification Successful',
    'Friendly AI Solution Official Email',
    '<p>Hello Harsh,</p><p>This is a live confirmation email sent directly from <strong>support@friendlyaisolution.com</strong> using <strong>Hostinger Business SMTP</strong> (<code>smtp.hostinger.com:465</code>).</p><p>Your outgoing email server configuration is 100% verified, active and working seamlessly!</p>',
    'Open Dashboard',
    'https://friendlyaisolution.com/index.php?page=dashboard'
);

$res = Mailer::send($to, $subject, $body);

if ($res) {
    echo json_encode([
        'status' => 'success',
        'message' => "Test email successfully dispatched to {$to} from support@friendlyaisolution.com via smtp.hostinger.com:465"
    ], JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'status' => 'failed',
        'message' => "Failed to send email to {$to}. Check SMTP logs in database."
    ], JSON_PRETTY_PRINT);
}
