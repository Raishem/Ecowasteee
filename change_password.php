<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $user_id = $_SESSION['user_id'];
    
    // Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'All password fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new_password) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } else {
        try {
            $conn = getDBConnection();
            
            // Get current user's password from database - UPDATED COLUMN NAME
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user) {
                // Verify current password
                if (password_verify($current_password, $user['password_hash'])) {
                    // Hash new password and update database - UPDATED COLUMN NAME
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    $update_stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                    $update_stmt->bind_param("si", $hashed_password, $user_id);
                    
                    if ($update_stmt->execute()) {
                        $success = 'Password changed successfully!';
                    } else {
                        $error = 'Failed to update password. Please try again.';
                    }
                    
                    $update_stmt->close();
                } else {
                    $error = 'Current password is incorrect.';
                }
            } else {
                $error = 'User not found.';
            }
            
            $stmt->close();
            $conn->close();
            
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
    
    // Store message in session to display on redirect
    if ($error) {
        $_SESSION['password_error'] = $error;
    } else {
        $_SESSION['password_success'] = $success;
    }
    
    // Redirect back to previous page
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'leaderboard.php'));
    exit();
}
?>