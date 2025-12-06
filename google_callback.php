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

    // Note: Currently, Google OAuth only provides name, email, and profile picture.
    // If in the future we request additional scopes and receive phone number, address, etc.,
    // we can check for these fields here and auto-create the account without redirecting to signup.
    // For now, new users must complete the signup form with required fields.


    $conn = getDBConnection();

    // Check if user exists
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // User exists - proceed with login
        $user = $result->fetch_assoc();

        // Link Google ID if not already linked
        if (empty($user['google_id'])) {
            $update_stmt = $conn->prepare("UPDATE users SET google_id=?, avatar=? WHERE user_id=?");
            $update_stmt->bind_param("ssi", $google_id, $avatar, $user['user_id']);
            $update_stmt->execute();
        }

        $user_id = $user['user_id'];
        $first_name = $user['first_name'];
        $last_name = $user['last_name'];

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
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['logged_in'] = true;

        header("Location: homepage.php");
        exit();
    } else {
        // New user - store Google data in session and redirect to signup
        $names = explode(' ', $name, 2);
        $first_name = $names[0];
        $last_name = $names[1] ?? '';

        // Store Google account information in session
        $_SESSION['google_signup_data'] = [
            'google_id' => $google_id,
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'name' => $name,
            'avatar' => $avatar
        ];

        $_SESSION['signup_message'] = "Please complete your profile to finish signing up with Google.";

        header("Location: signup.php");
        exit();
    }
} else {
    $_SESSION['login_error'] = 'No code parameter provided by Google.';
    header('Location: login.php');
    exit();
}
