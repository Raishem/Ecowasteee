<?php

$GOOGLE_CLIENT_ID = '1009180314941-a79k3e4fqf3rc4rnl96ih3m4v991cbv4.apps.googleusercontent.com';
$GOOGLE_CLIENT_SECRET = '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';

$GOOGLE_REDIRECT_URI = 'http://localhost/ecowaste/google_callback.php';


// OAuth endpoints
$GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
$GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
$GOOGLE_USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';


// Scopes we request
$GOOGLE_SCOPES = [
'openid',
'email',
'profile',
];


// Optional: state token name to protect against CSRF
$GOOGLE_STATE_SESSION_KEY = 'google_oauth_state';