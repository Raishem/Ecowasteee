<?php
// Start session for CSRF and other session-based features
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database credentials as constants for both MySQLi and PDO
define('DB_HOST', 'localhost');
define('DB_NAME', 'ecowaste');
define('DB_USER', 'root');
define('DB_PASS', '');

// Database connection (PDO)
function getDBConnection() {
    $host = DB_HOST;
    $db   = DB_NAME;
    $user = DB_USER;
    $pass = DB_PASS;
    $charset = "utf8mb4";

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// CSRF helpers
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Constants
define('REMEMBER_ME_EXPIRY', 60 * 60 * 24 * 30); // 30 days
?>
