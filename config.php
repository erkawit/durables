<?php
date_default_timezone_set('Asia/Bangkok');

// ── Load local .env.local / .env if present (when env vars are not already injected by host) ──
foreach (['.env.local', '.env'] as $envFile) {
    $envPath = __DIR__ . '/' . $envFile;
    if (is_file($envPath) && is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || strpos($trimmed, '=') === false) {
                continue;
            }
            list($key, $val) = explode('=', $trimmed, 2);
            $key = trim($key);
            $val = trim($val);
            if ((substr($val, 0, 1) === '"' && substr($val, -1) === '"') ||
                (substr($val, 0, 1) === "'" && substr($val, -1) === "'")) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '' && getenv($key) === false) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
            }
        }
        break;
    }
}

// ── Database Configuration (Supports Neon DATABASE_URL, Supabase Env Vars & Local MySQL) ──
$database_url = getenv('DATABASE_URL');
if ($database_url) {
    $parts = parse_url($database_url);
    if ($parts && isset($parts['host'])) {
        $db_driver = 'pgsql';
        $db_host   = $parts['host'];
        $db_port   = isset($parts['port']) ? (string)$parts['port'] : '5432';
        $db_name   = isset($parts['path']) ? ltrim($parts['path'], '/') : 'neondb';
        $db_user   = isset($parts['user']) ? urldecode($parts['user']) : '';
        $db_pass   = isset($parts['pass']) ? urldecode($parts['pass']) : '';
        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);
        }
        $db_ssl = $queryParams['sslmode'] ?? 'require';
        putenv('DB_DRIVER=pgsql');
    }
}

if (!isset($db_driver)) {
    $db_driver = getenv('DB_DRIVER') ?: 'mysql'; // 'pgsql' for Neon/Supabase, 'mysql' for local XAMPP
    $db_host   = getenv('DB_HOST') ?: 'localhost';
    $db_port   = getenv('DB_PORT') ?: ($db_driver === 'pgsql' ? '5432' : '3306');
    $db_name   = getenv('DB_NAME') ?: 'durables_db';
    $db_user   = getenv('DB_USER') ?: 'root';
    $db_pass   = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
    $db_ssl    = getenv('DB_SSLMODE') ?: ($db_driver === 'pgsql' ? 'require' : '');
}

try {
    if ($db_driver === 'pgsql') {
        $dsn = "pgsql:host=$db_host;port=$db_port;dbname=$db_name";
        if (!empty($db_ssl)) {
            $dsn .= ";sslmode=$db_ssl";
        }
        $pdo = new PDO($dsn, $db_user, $db_pass);
    } else {
        $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}

// ── Session Handling (Database Session for Vercel Serverless / Multi-instance) ──
$use_db_session = (getenv('VERCEL') || getenv('USE_DB_SESSION') === 'true');
if ($use_db_session) {
    require_once __DIR__ . '/session_handler.php';
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $handler = new DatabaseSessionHandler($pdo);
    session_set_save_handler($handler, true);
    session_start();
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Helper function for logging
function logAction($pdo, $userId, $action, $page, $details)
{
    $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action_type, page_name, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $page, $details, ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')]);
}

// Base URL configuration
$vercel_url = getenv('VERCEL_URL');
$base_url = getenv('BASE_URL') ?: ($vercel_url ? 'https://' . rtrim($vercel_url, '/') . '/' : 'http://localhost/durables/');

// Load system settings
$sys_settings = [];
try {
    if ($db_driver === 'pgsql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id SERIAL PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    if ($stmtSettings) {
        while ($row = $stmtSettings->fetch(PDO::FETCH_ASSOC)) {
            $sys_settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // Handled in case error occurs
}

// Ensure all settings keys have defaults if not set
$defaults = [
    'site_name_th' => 'ระบบเบิกวัสดุ',
    'site_name_en' => 'DURABLES',
    'logo_path' => 'img/durables_logo.jpg',
    'favicon_path' => 'img/durables_logo.jpg',
    'theme_color' => '#17365a',
    'telegram_bot_token' => '',
    'telegram_bot_username' => '',
    'telegram_group_chat_id' => '',
    'node_service_enabled' => '1'
];
foreach ($defaults as $key => $val) {
    if (!isset($sys_settings[$key]) || $sys_settings[$key] === '') {
        $sys_settings[$key] = $val;
    }
}
