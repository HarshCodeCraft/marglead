<?php
require_once __DIR__ . '/../includes/config.php';
$_SESSION['waba_onboarding_shown'] = true;
$_SESSION['waba_modal_dismissed'] = true;
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
