<?php
/**
 * Marg ERP CRM - Logout handler
 */

require_once __DIR__ . '/../includes/config.php';

if (!empty($_SESSION['user_name'])) {
    logActivity('LOGOUT', 'Authentication', "User {$_SESSION['user_name']} logged out.");
}

session_unset();
session_destroy();

header("Location: login.php");
exit;
