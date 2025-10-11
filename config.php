<?php
// Start session for CSRF and other session-based features
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Configuration and helper functions only

// Database connection
function getDBConnection() {
    $conn = new mysqli('localhost', 'root', '', 'ecowaste');
    if ($conn->connect_error) {
        die('Database connection failed: ' . $conn->connect_error);
    }
    return $conn;
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
define('REMEMBER_ME_EXPIRY', 86400 * 30); // 30 days

?>