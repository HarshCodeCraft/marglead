<?php
/**
 * Friendly AI Solution - Government Documents Verification API Configuration
 * Supports Live Indian Government API Gateways (Sandbox.co.in / Surepass / Cashfree / Karza)
 * and Algorithmic Checksum & Sandbox Modes.
 */

if (!defined('GOV_VERIFICATION_MODE')) {
    $mode = function_exists('getSystemSetting') ? getSystemSetting('gov_verification_mode', 'sandbox') : 'sandbox';
    define('GOV_VERIFICATION_MODE', $mode);
}

if (!defined('GOV_API_PROVIDER')) {
    $provider = function_exists('getSystemSetting') ? getSystemSetting('gov_api_provider', 'sandbox_co_in') : 'sandbox_co_in';
    define('GOV_API_PROVIDER', $provider);
}

if (!defined('GOV_API_KEY')) {
    $key = function_exists('getSystemSetting') ? getSystemSetting('gov_api_key', '') : '';
    define('GOV_API_KEY', $key);
}

if (!defined('GOV_STRICT_CHECKSUM')) {
    // Setting: false (User-friendly Format & State Validation) or true (Strict Mathematical Modulo 36 / Verhoeff Checksum)
    $strict = function_exists('getSystemSetting') ? (getSystemSetting('gov_strict_checksum', 'false') === 'true') : false;
    define('GOV_STRICT_CHECKSUM', $strict);
}

/**
 * Regex Patterns for Indian Identity & Tax Documents
 */
$GOV_DOC_PATTERNS = [
    'pan' => '/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
    'aadhaar' => '/^[2-9]{1}[0-9]{11}$/',
    'gstin' => '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
    'udyam' => '/^UDYAM-[A-Z]{2}-[0-9]{2}-[0-9]{7}$/i'
];

/**
 * Algorithmic Verhoeff Checksum for Aadhaar Card Validation
 */
function validateAadhaarVerhoeff($aadhaarNumber) {
    $aadhaarNumber = preg_replace('/\D/', '', $aadhaarNumber);
    if (strlen($aadhaarNumber) !== 12) return false;

    $d = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 6, 7, 8, 9, 0, 1, 2, 3, 4],
        [6, 7, 8, 9, 5, 1, 2, 3, 4, 0],
        [7, 8, 9, 5, 6, 2, 3, 4, 0, 1],
        [8, 9, 5, 6, 7, 3, 4, 0, 1, 2],
        [9, 5, 6, 7, 8, 4, 0, 1, 2, 3]
    ];

    $p = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8]
    ];

    $c = 0;
    $invertedArray = array_reverse(str_split($aadhaarNumber));

    for ($i = 0; $i < count($invertedArray); $i++) {
        $c = $d[$c][$p[$i % 8][(int)$invertedArray[$i]]];
    }

    return ($c === 0);
}

/**
 * Algorithmic Checksum for GSTIN Validation (Modulo 36)
 */
function validateGSTINChecksum($gstin) {
    $gstin = strtoupper(trim($gstin));
    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin)) {
        return false;
    }

    $chars = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $cMap = [];
    for ($i = 0; $i < strlen($chars); $i++) {
        $cMap[$chars[$i]] = $i;
    }

    $factor = 1;
    $sum = 0;
    $mod = 36;

    for ($i = 0; $i < 14; $i++) {
        $codePoint = $cMap[$gstin[$i]];
        $digit = $factor * $codePoint;
        $factor = ($factor === 1) ? 2 : 1;
        $digit = intval($digit / $mod) + ($digit % $mod);
        $sum += $digit;
    }

    $checkDigitCodePoint = ($mod - ($sum % $mod)) % $mod;
    $checkChar = $chars[$checkDigitCodePoint];

    return ($gstin[14] === $checkChar);
}
