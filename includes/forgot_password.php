<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
    
    if ($email) {
        try {
            $conn = getDBConnection();
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() > 0) {
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour
                
                $stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE email = ?");
                $stmt->execute([$token, $expiry, $email]);
                
                // Send email with reset link (implementation depends on your mailer)
                $resetLink = "https://yourdomain.com/reset_password.php?token=$token";
                // sendPasswordResetEmail($email, $resetLink);
                
                $_SESSION['message'] = 'Password reset link sent to your email';
            } else {
                $_SESSION['error'] = 'Email not found';
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
            $_SESSION['error'] = 'An error occurred';
        }
    } else {
        $_SESSION['error'] = 'Invalid email address';
    }
    header('Location: forgot_password.php');
    exit();
}

// This code appears to be duplicated and outside the POST handler
// It should be removed or moved inside the POST handler

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
    <div class="login-container">
        <div class="content-container">
            <h1>Reset Your Password</h1>
            
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert success"><?= htmlspecialchars($_SESSION['message']) ?></div>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert error"><?= htmlspecialchars($_SESSION['error']) ?></div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <button type="submit" class="login-btn">Send Reset Link</button>
            </form>
            <p class="signup-link">Remember your password? <a href="login.php">Login here</a></p>
        </div>
    </div>
</body>
</html>