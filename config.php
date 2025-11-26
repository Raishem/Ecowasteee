<?php
// Start session for CSRF and other session-based features
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Manila'); // ✅ global fix

// Configuration and helper functions only

// Database connection
function getDBConnection() {
    $conn = new mysqli('localhost', 'root', '', 'ecowaste');
    if ($conn->connect_error) 
        die('Database connection failed: ' . $conn->connect_error);
    
    // ✅ Force timezone for this connection
    $conn->query("SET time_zone = '+08:00'");
    date_default_timezone_set('Asia/Manila');

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