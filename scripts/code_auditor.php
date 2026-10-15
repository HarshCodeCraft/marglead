<?php
/**
 * MargLead Automated Code Resilience & Security Auditor
 * Usage: php scripts/code_auditor.php [optional_path_or_file]
 */

if (php_sapi_name() !== 'cli') {
    die("Access denied. CLI only.\n");
}

$target = $argv[1] ?? __DIR__ . '/..';
$target = realpath($target);

if (!$target || !file_exists($target)) {
    die("Error: Target path does not exist.\n");
}

echo "========================================================\n";
echo " MARGLEAD CODE RESILIENCE & ADVERSARIAL AUDITOR \n";
echo " Target: " . $target . "\n";
echo "========================================================\n\n";

$issues = [];
$scannedCount = 0;

$ignoreDirs = ['.git', 'vendor', 'node_modules', 'uploads', 'scratch', 'logs'];

function scanPath($path, &$issues, &$scannedCount, $ignoreDirs) {
    if (is_file($path)) {
        auditFile($path, $issues);
        $scannedCount++;
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $pathname = $item->getPathname();
        
        // Skip ignored directories
        $skip = false;
        foreach ($ignoreDirs as $ignored) {
            if (strpos($pathname, DIRECTORY_SEPARATOR . $ignored . DIRECTORY_SEPARATOR) !== false ||
                strpos($pathname, DIRECTORY_SEPARATOR . $ignored) === (strlen($pathname) - strlen($ignored) - 1)) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;

        if ($item->isFile()) {
            $ext = strtolower($item->getExtension());
            if (in_array($ext, ['php', 'js', 'html'])) {
                auditFile($item->getPathname(), $issues);
                $scannedCount++;
            }
        }
    }
}

function auditFile($file, &$issues) {
    $content = file_get_contents($file);
    $relPath = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', $file);
    $lines = explode("\n", $content);

    // Rule 1: No Emojis (Corporate Standard)
    $emojiRegex = '/[\x{1F300}-\x{1F5FF}\x{1F600}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}]/u';
    foreach ($lines as $idx => $line) {
        if (preg_match($emojiRegex, $line)) {
            $issues[] = [
                'type' => 'UI_EMOJI',
                'severity' => 'WARNING',
                'file' => $relPath,
                'line' => $idx + 1,
                'desc' => 'Emoji detected. Replace with clean Lucide SVG or CSS icon.'
            ];
            break; // Report once per file
        }
    }

    // Rule 2: SQL Injection Vectors (Interpolated variables in queries)
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        foreach ($lines as $idx => $line) {
            if (preg_match('/->(?:query|prepare)\s*\(\s*["\'].*?\$(?:_POST|_GET|_REQUEST|[a-zA-Z0-9_]+).*?["\']\s*\)/i', $line)) {
                // Ignore if it is just a table placeholder
                if (!preg_match('/\{[a-zA-Z0-9_]+Table\}/', $line)) {
                    $issues[] = [
                        'type' => 'SECURITY_SQLI',
                        'severity' => 'HIGH',
                        'file' => $relPath,
                        'line' => $idx + 1,
                        'desc' => 'Potential unescaped variable inside SQL query string. Use parameterized ? markers.'
                    ];
                }
            }
        }

        // Rule 3: cURL Timeout Verification (Prevent Server Hanging)
        if (strpos($content, 'curl_init') !== false) {
            if (strpos($content, 'CURLOPT_TIMEOUT') === false && strpos($content, 'CURLOPT_CONNECTTIMEOUT') === false) {
                $issues[] = [
                    'type' => 'RESILIENCE_TIMEOUT',
                    'severity' => 'MEDIUM',
                    'file' => $relPath,
                    'line' => 1,
                    'desc' => 'cURL request missing CURLOPT_TIMEOUT. Outgoing requests can freeze the PHP thread.'
                ];
            }
        }
    }
}

scanPath($target, $issues, $scannedCount, $ignoreDirs);

echo "Scanned files: {$scannedCount}\n";
echo "Total findings: " . count($issues) . "\n\n";

if (empty($issues)) {
    echo "[PASS] All scanned files comply with security, resilience, and UI standards.\n";
    exit(0);
}

foreach ($issues as $issue) {
    echo sprintf("[%s] [%s] %s:%d\n    %s\n\n", $issue['severity'], $issue['type'], $issue['file'], $issue['line'], $issue['desc']);
}

echo "Auditor finished with " . count($issues) . " alert(s).\n";
