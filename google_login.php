<?php
session_start();
require_once 'google_config.php';

// Generate random state for CSRF protection
$state = bin2hex(random_bytes(16));
$_SESSION[$GOOGLE_STATE_SESSION_KEY] = $state;

$params = [
    'client_id' => $GOOGLE_CLIENT_ID,
    'redirect_uri' => $GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => implode(' ', $GOOGLE_SCOPES),
    'access_type' => 'offline',
    'prompt' => 'select_account',
    'state' => $state,
];

$auth_url = $GOOGLE_AUTH_URL . '?' . http_build_query($params);
header('Location: ' . $auth_url);
exit();
