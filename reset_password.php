<?php
session_start();
require_once "config.php";
$conn = getDBConnection();

if (!isset($_GET['token'])) {
    die("Invalid link");
}

$token = $_GET['token'];

// Verify token
$stmt = $conn->prepare("SELECT user_id, expires_at FROM password_resets WHERE token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    die("Invalid or expired link.");
}

$row = $res->fetch_assoc();
if (strtotime($row['expires_at']) < time()) {
    die("This link has expired.");
}

$userId = $row['user_id'];

// Handle reset form
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $password = password_hash($_POST["password"], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
    $stmt->bind_param("si", $password, $userId);
    $stmt->execute();

    // Delete used token
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();

    $_SESSION['reset_message'] = "Password reset successful! You can now log in.";
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set New Password</title>
    <link rel="stylesheet" href="assets/css/reset_password.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-key"></i></div>
            <h1>Set New Password</h1>
            <p>Please enter your new password below</p>
        </div>

        <form method="POST">
            <div class="form-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" placeholder="Enter new password" required>
            </div>
            <button type="submit" class="btn"><i class="fas fa-check"></i> Reset Password</button>
        </form>

        <div class="back-to-login">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>

        <div class="eco-tip">
            <i class="fas fa-leaf"></i> Tip: Choose a password that’s at least 8 characters long and mixes letters, numbers, and symbols 🌍
        </div>
    </div>
</body>
</html>
