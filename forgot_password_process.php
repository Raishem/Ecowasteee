<?php
session_start();
require_once "config.php";
$conn = getDBConnection();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);

    // Check if email exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $userId = $row['user_id'];

        // Generate token
        $token = bin2hex(random_bytes(32));
        $expires = date("Y-m-d H:i:s", strtotime("+1 hour"));

        // Store token
        $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $userId, $token, $expires);
        $stmt->execute();

        // Send link (for now just show it)
        $resetLink = "http://localhost/ecowaste/reset_password.php?token=$token";

        $_SESSION['reset_message'] = "A reset link has been sent. <br> <a href='$resetLink'>Click here to reset</a>";
    } else {
        $_SESSION['reset_error'] = "No account found with that email.";
    }

    header("Location: forgot_password.php");
    exit();
}
