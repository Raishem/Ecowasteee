<?php

session_start();
require_once 'config.php';
$csrf_token = generateCSRFToken();

// Redirect if already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && !empty($_SESSION['user_id'])) {
    header('Location: homepage.php');
    exit();
}

// Handle errors from login attempt
$error = '';
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login to EcoWaste</title>
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
</head>
<body>
    <header class="main-header">
        <div class="logo-image">
            <a href="index.php">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo" class="logo-img">
            </a>
        </div>
    </header>

    <div class="login-container">
        <div class="left-section">
            <div class="curved-design">
                <div class="curve curve-large"></div>
                <div class="curve curve-medium"></div>
                <div class="curve curve-small"></div>
            </div>
            <div class="content-container">
                <h1>Welcome Back!</h1>
                <p class="subtitle">Login to continue supporting sustainable waste donations.</p>
                
                <?php if ($error): ?>
                    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form action="login_process.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="Enter your email" required 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    </div>

                    <div class="remember-me">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Remember me</label>
                    </div>

                    <div class="forgot-password">
                        <a href="forgot_password.php">Forgot password?</a>
                    </div>

                    <button type="submit" class="login-btn">Login</button>
                    
                    <div class="divider">or</div>
                    
                    <div class="social-login">
                        <button type="button" class="social-btn google">
                            <i class="fab fa-google"></i> Continue with Google
                        </button>
                        <button type="button" class="social-btn facebook">
                            <i class="fab fa-facebook-f"></i> Continue with Facebook
                        </button>
                    </div>
                    
                    <p class="signup-link">Don't have an account? <a href="signup.php">Sign up</a></p>
                </form>
            </div>
        </div>
        
        <div class="right-section">
            <div class="green-curves">
                <div class="green-curve green-curve-1"></div>
                <div class="green-curve green-curve-2"></div>
                <div class="green-curve green-curve-3"></div>
            </div>
        </div>
    </div>
</body>
</html>