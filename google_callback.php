<?php
session_start();
require_once 'config.php';
require_once 'google_config.php';
require_once 'vendor/autoload.php';

// ---------- CSRF STATE CHECK ----------
if (!isset($_SESSION[$GOOGLE_STATE_SESSION_KEY])) {
    die('Session state not set. Please start login from login.php.');
}

if (!isset($_GET['state']) || $_GET['state'] !== $_SESSION[$GOOGLE_STATE_SESSION_KEY]) {
    die('Invalid state parameter. Possible CSRF attack.');
}

// State is valid, remove it from session
unset($_SESSION[$GOOGLE_STATE_SESSION_KEY]);

$client = new Google_Client();
$client->setClientId($GOOGLE_CLIENT_ID);
$client->setClientSecret($GOOGLE_CLIENT_SECRET);
$client->setRedirectUri($GOOGLE_REDIRECT_URI);
$client->addScope('email');
$client->addScope('profile');

if (isset($_GET['code'])) {
    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    } catch (Exception $e) {
        $_SESSION['login_error'] = 'Google login failed: ' . $e->getMessage();
        header('Location: login.php');
        exit();
    }

    if (isset($token['error'])) {
        $_SESSION['login_error'] = 'Google login failed: ' . $token['error'];
        header('Location: login.php');
        exit();
    }

    $client->setAccessToken($token['access_token']);

    $google_oauth = new Google_Service_Oauth2($client);
    $google_account_info = $google_oauth->userinfo->get();

    $email = $google_account_info->email;
    $name = $google_account_info->name;
    $google_id = $google_account_info->id;
    $avatar = $google_account_info->picture;

    $conn = getDBConnection();

    // Check if user exists
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (empty($user['google_id'])) {
            $update_stmt = $conn->prepare("UPDATE users SET google_id=? WHERE user_id=?");
            $update_stmt->bind_param("si", $google_id, $user['user_id']);
            $update_stmt->execute();
        }
        $user_id = $user['user_id'];
        $first_name = $user['first_name'];
        $last_name = $user['last_name'];
    } else {
        $names = explode(' ', $name, 2);
        $first_name = $names[0];
        $last_name = $names[1] ?? '';

        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, google_id, avatar, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssss", $first_name, $last_name, $email, $google_id, $avatar);
        $stmt->execute();
        $user_id = $stmt->insert_id;
    }

    // Generate a remember token
    $remember_token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));

    // Update user record
    $update_token_stmt = $conn->prepare("UPDATE users SET remember_token=?, token_expiry=? WHERE user_id=?");
    $update_token_stmt->bind_param("ssi", $remember_token, $expiry, $user_id);
    $update_token_stmt->execute();

    // Set cookie
    setcookie('remember_token', $remember_token, time() + (30 * 24 * 60 * 60), "/"); // 30 days

    // Set session
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;
    $_SESSION['first_name'] = $first_name;   // <-- added
    $_SESSION['last_name'] = $last_name;     // <-- added
    $_SESSION['logged_in'] = true;

    header("Location: homepage.php");
    exit();
} else {
    $_SESSION['login_error'] = 'No code parameter provided by Google.';
    header('Location: login.php');
    exit();
}
