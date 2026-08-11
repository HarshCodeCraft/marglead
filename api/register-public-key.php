<?php
/**
 * Marg CRM - Register WhatsApp Flow Public Key with Meta Graph API
 * 
 * URL: https://ladder-giver-splendid.ngrok-free.dev/marglead/api/register-public-key.php
 * 
 * Performs POST to: https://graph.facebook.com/v20.0/{PHONE_NUMBER_ID}/whatsapp_business_encryption
 * sending 'business_public_key' from config/public_key.pem.
 */

require_once __DIR__ . '/whatsapp-api.php';

header('Content-Type: application/json; charset=utf-8');

$pubKeyPath = __DIR__ . '/../config/public_key.pem';

if (!file_exists($pubKeyPath)) {
    json_response(false, 'Public key file missing at config/public_key.pem', null, 400);
}

$publicKeyPem = file_get_contents($pubKeyPath);

$whatsapp = new WhatsAppAPI($pdo);
$result = $whatsapp->registerPublicKey($publicKeyPem);

if ($result['success']) {
    json_response(true, 'RSA Public Key registered with Meta Graph API successfully!', [
        'phone_number_id' => PHONE_NUMBER_ID,
        'graph_api_response' => $result['response'],
        'public_key' => $publicKeyPem
    ]);
} else {
    json_response(false, 'Failed to register RSA Public Key with Meta Graph API.', [
        'phone_number_id' => PHONE_NUMBER_ID,
        'http_code' => $result['http_code'],
        'error_response' => $result['response']
    ], 500);
}
