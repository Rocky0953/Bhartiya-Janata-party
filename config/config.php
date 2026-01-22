<?php
// Configuration file for Tic-Tac-Toe Tournament

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'tic_tac_toe_tournament');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Application settings
define('APP_NAME', 'Tic-Tac-Toe Tournament');
define('APP_URL', getenv('APP_URL') ?: 'https://yourdomain.com');
define('TIMEZONE', 'Asia/Kolkata');

// Telegram settings
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_WEBAPP_URL', APP_URL . '/webapp/');

// Kukupay settings
define('KUKUPAY_API_KEY', getenv('KUKUPAY_API_KEY') ?: '');
define('KUKUPAY_API_URL', 'https://api.kukupay.com/v1/'); // Replace with actual API URL

// File upload settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png']);

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Set to 0 for localhost development
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Error reporting
if (getenv('APP_ENV') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Initialize database
require_once __DIR__ . '/../src/Database.php';
\TicTacToe\Database::init([
    'host' => DB_HOST,
    'dbname' => DB_NAME,
    'username' => DB_USER,
    'password' => DB_PASS,
    'charset' => DB_CHARSET
]);

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'TicTacToe\\';
    $base_dir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Create uploads directory if it doesn't exist
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Helper functions
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getSetting($key) {
    static $cache = [];

    if (!isset($cache[$key])) {
        $db = \TicTacToe\Database::getInstance();
        $stmt = $db->prepare("SELECT value FROM settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        $cache[$key] = $result ? $result['value'] : null;
    }

    return $cache[$key];
}

function updateSetting($key, $value) {
    $db = \TicTacToe\Database::getInstance();
    $stmt = $db->prepare("
        INSERT INTO settings (`key`, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ");
    $stmt->execute([$key, $value]);

    // Clear cache
    static $cache = [];
    unset($cache[$key]);
}