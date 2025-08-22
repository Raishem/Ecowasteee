<?php
session_start();
require_once 'config.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || $password !== $confirm_password) {
        $error = 'Passwords do not match or are empty';
    } else {
        try {
            $conn = getDBConnection();
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE reset_token = ? AND reset_expiry > NOW()");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                
                $stmt = $conn->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expiry = NULL WHERE user_id = ?");
                $stmt->bind_param("si", $hashedPassword, $user['user_id']);
                $stmt->execute();
                
                $success = 'Password updated successfully. You can now login.';
            } else {
                $error = 'Invalid or expired token';
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
            $error = 'An error occurred';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
    <div class="login-container">
        <div class="content-container">
            <h1>Set New Password</h1>
            
            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="login-btn">Update Password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>