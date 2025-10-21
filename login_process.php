<?php

session_start();
require_once 'config.php';

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    $_SESSION['login_error'] = 'Invalid security token';
    header('Location: login.php');
    exit();
}

// Validate inputs
$email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember']);

if (!$email || empty($password)) {
    $_SESSION['login_error'] = 'Please provide both email and password';
    header('Location: login.php');
    exit();
}

try {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT user_id, email, password_hash, first_name FROM users WHERE email = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['login_error'] = 'Database error.';
        header('Location: login.php');
        exit();
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    // Query executed

    if ($result->num_rows === 0) {
        $_SESSION['login_error'] = 'Invalid email or password';
        header('Location: login.php');
        exit();
    }

    $user = $result->fetch_assoc();
    // user record retrieved

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        $_SESSION['login_error'] = 'Invalid email or password';
        header('Location: login.php');
        exit();
    }

    // Check if email is verified
    //if (!$user['verified']) {
    ///    $_SESSION['login_error'] = 'Please verify your email first';
    ///    header('Location: login.php');
    ///    exit();
    ///}

    // Set session variables
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['first_name'] = $user['first_name'];

    // Remember me functionality
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + REMEMBER_ME_EXPIRY);
        setcookie('remember_token', $token, [
            'expires' => strtotime($expiry),
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        $stmt = $conn->prepare("UPDATE users SET remember_token = ?, token_expiry = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $token, $expiry, $user['user_id']);
        $stmt->execute();
    }

    header('Location: homepage.php');
    exit();

} catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An error occurred. Please try again.';
    header('Location: login.php');
    exit();
}
?>