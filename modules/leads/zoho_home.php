<?php
/**
 * Backwards Compatibility Redirect / Wrapper
 * Routes all legacy calls to Modern View Workspace
 */
if (isset($_GET['view']) && strtolower($_GET['view']) === 'zoho') {
    if (!headers_sent()) {
        header("Location: index.php?page=leads&view=modern");
        exit;
    }
}

require_once __DIR__ . '/modern_home.php';
