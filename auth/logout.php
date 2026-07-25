<?php
/**
 * Marg ERP CRM - Logout handler
 */

session_start();
session_unset();
session_destroy();

header("Location: login.php");
exit;
