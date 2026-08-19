<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup Wizard - Marg Soft Solution</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Outfit:wght@600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0f19;
            --card: #121826;
            --border: #1e293b;
            --text: #f1f5f9;
            --muted: #94a3b8;
            --primary: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 1.5rem;
        }
        .setup-card {
            background-color: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 2.5rem;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            text-align: center;
        }
        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        p {
            color: var(--muted);
            font-size: 0.875rem;
            margin-bottom: 2rem;
        }
        .step-log {
            text-align: left;
            background-color: #080b12;
            padding: 1.25rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.825rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .log-entry {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .status-ok { color: var(--success); font-weight: bold; }
        .status-err { color: var(--danger); font-weight: bold; }
        .btn {
            display: inline-block;
            background-color: var(--primary);
            color: #ffffff;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .btn:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="setup-card">
        <h1>Database Setup Wizard</h1>
        <p>Connecting Marg Lead Management System to MySQL server</p>
        
        <div class="step-log">
            <?php
            require_once __DIR__ . '/config/config.php';
            $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
            $db_user = defined('DB_USER') ? DB_USER : 'u978772385_friendlyaidata';
            $db_pass = defined('DB_PASS') ? DB_PASS : '';
            $db_name = defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata';
            $db_port = defined('DB_PORT') ? DB_PORT : '3306';
            
            // 1. Connection test (try primary port then fallback port)
            $conn = null;
            $last_error = '';
            $ports_to_try = [$db_port, ($db_port === '3306' ? '3307' : '3306')];
            foreach ($ports_to_try as $port) {
                try {
                    $conn = new PDO("mysql:host=$db_host;port=$port", $db_user, $db_pass);
                    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    break;
                } catch (PDOException $e) {
                    $last_error = $e->getMessage();
                }
            }

            if (!$conn) {
                echo '<div class="log-entry"><span>Connecting to MySQL server...</span><span class="status-err">FAILED</span></div>';
                echo '<div style="color: var(--danger); margin-top: 0.5rem;">Error: ' . htmlspecialchars($last_error) . '</div>';
                echo '</div><a href="setup_db.php" class="btn">Retry Connection</a></div></body></html>';
                exit;
            }
            echo '<div class="log-entry"><span>Connecting to MySQL server...</span><span class="status-ok">SUCCESS</span></div>';

            // 2. Read schema SQL
            $schema_file = __DIR__ . '/schema.sql';
            if (!file_exists($schema_file)) {
                echo '<div class="log-entry"><span>Locating schema.sql file...</span><span class="status-err">MISSING</span></div>';
                echo '</div><a href="setup_db.php" class="btn">Retry Setup</a></div></body></html>';
                exit;
            }
            echo '<div class="log-entry"><span>Locating schema.sql file...</span><span class="status-ok">FOUND</span></div>';

            // 3. Execute queries
            try {
                $sql = file_get_contents($schema_file);
                
                $conn->exec($sql);
                
                // Migration check: ensure otp_code and otp_expires_at exist on users table
                try {
                    $conn->exec("ALTER TABLE `{$db_name}`.users ADD COLUMN otp_code VARCHAR(10) NULL");
                } catch (Exception $ex) {}
                try {
                    $conn->exec("ALTER TABLE `{$db_name}`.users ADD COLUMN otp_expires_at DATETIME NULL");
                } catch (Exception $ex) {}

                // Overwrite passwords with local PHP engine bcrypt hashes to ensure match
                $hash = password_hash('password123', PASSWORD_DEFAULT);
                $updatePass = $conn->prepare("UPDATE `{$db_name}`.users SET password = ?");
                $updatePass->execute([$hash]);
                
                echo '<div class="log-entry"><span>Creating database "' . htmlspecialchars($db_name) . '"...</span><span class="status-ok">CREATED</span></div>';
                echo '<div class="log-entry"><span>Creating tables & schema...</span><span class="status-ok">COMPLETED</span></div>';
                echo '<div class="log-entry"><span>Seeding database records...</span><span class="status-ok">SEEDED</span></div>';
                $setup_success = true;
            } catch (PDOException $e) {
                echo '<div class="log-entry"><span>Executing database schema...</span><span class="status-err">FAILED</span></div>';
                echo '<div style="color: var(--danger); margin-top: 0.5rem;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
                $setup_success = false;
            }
            ?>
        </div>

        <?php if ($setup_success): ?>
            <a href="index.php?page=dashboard" class="btn">Launch Dashboard</a>
        <?php else: ?>
            <a href="setup_db.php" class="btn" style="background-color: var(--danger);">Retry Database Setup</a>
        <?php endif; ?>
    </div>
</body>
</html>
