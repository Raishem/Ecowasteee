<?php
require_once "config.php";
$conn = getDBConnection();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);

    // Check if email exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $_SESSION['reset_error'] = "No account found with that email.";
        header("Location: forgot_password.php");
        exit();
    }

}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
    <link rel="stylesheet" href="assets/css/forgot_password.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="logo-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h1>Reset Password</h1>
            <p>Enter your email to receive a reset link</p>
        </div>
        
        <?php if (isset($_SESSION['reset_message'])): ?>
            <div class="success-message"><i class="fas fa-check-circle"></i> <?= $_SESSION['reset_message']; ?></div>
            <?php unset($_SESSION['reset_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['reset_error'])): ?>
            <div class="error"><?= $_SESSION['reset_error']; ?></div>
            <?php unset($_SESSION['reset_error']); ?>
        <?php endif; ?>

        <form action="forgot_password_process.php" method="POST">
            <div class="form-group input-with-icon">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" placeholder="Enter your email address" required>
            </div>
            
            <button type="submit" class="btn">
                <i class="fas fa-paper-plane"></i> Send Reset Link
            </button>
        </form>
        
        <div class="back-to-login">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>

        <div class="eco-tip">
            <i class="fas fa-leaf"></i> Tip: Use a strong password with letters, numbers, and symbols to keep your account safe 🌱
        </div>
    </div>
</body>
</html>
