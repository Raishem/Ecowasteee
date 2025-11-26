<?php
session_start();
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE users SET remember_token = NULL, token_expiry = NULL WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
}

// Clear the cookie
setcookie('remember_token', '', time() - 3600, '/', '', false, true);

// Destroy session
session_unset();
session_destroy();

header('Location: login.php');
exit();
?>
