<?php
require_once 'config.php';
$conn = getDBConnection();

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $conn->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE verification_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo "Your email has been verified! You can now <a href='login.php'>log in</a>.";
    } else {
        echo "Invalid or expired verification link.";
    }
} else {
    echo "No verification token provided.";
}
?>
