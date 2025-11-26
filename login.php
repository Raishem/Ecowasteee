<?php

session_start();
require_once 'config.php';
$csrf_token = generateCSRFToken();

// Auto-login using remember_token if not logged in yet
if (!isset($_SESSION['logged_in']) && isset($_COOKIE['remember_token'])) {
    $conn = getDBConnection();
    $token = $_COOKIE['remember_token'];

    $stmt = $conn->prepare("SELECT user_id, email, first_name, last_name FROM users 
                            WHERE remember_token = ? AND token_expiry > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Recreate the session
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];

        header('Location: homepage.php');
        exit();
    }
}

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
                
                <?php if (isset($_SESSION['reset_message'])): ?>
                    <div class="success-banner">
                        <i class="fas fa-check-circle"></i>
                        <?= $_SESSION['reset_message']; ?>
                        <span class="close-btn">&times;</span>
                    </div>
                    <?php unset($_SESSION['reset_message']); ?>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="error-banner">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error); ?>
                        <span class="close-btn">&times;</span>
                    </div>
                <?php endif; ?>

                
                <form action="login_process.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="Enter your email" required 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group password-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" placeholder="Enter your password" required>
                            <i class="fa-solid fa-eye toggle-password" id="togglePassword"></i>
                        </div>
                    </div>


                    <div class="form-options">
                        <div class="remember-me">
                            <input type="checkbox" id="remember" name="remember">
                            <label for="remember">Remember me</label>
                        </div>
                        
                        <div class="forgot-password">
                            <a href="forgot_password.php">Forgot password?</a>
                        </div>
                    </div>

                    <button type="submit" class="login-btn">Login</button>
                    
                    <div class="divider">or</div>
                    
                    <div class="social-login">
                        <a href="google_login.php" class="social-btn google" >
                            <i class="fab fa-google"></i> Continue with Google
                    </a>
                        <button type="button" class="social-btn facebook" id="facebookBtn"> 
                            <i class="fab fa-facebook-f"></i> (Coming Soon)
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
                <div class="floating-element floating-element-1"></div>
                <div class="floating-element floating-element-2"></div>
                <div class="floating-element floating-element-3"></div>
            </div>
        </div>
    </div>

<!-- Popup Toast -->
<div id="popupToast" class="popup-toast">
    <i class="fas fa-info-circle"></i>
    <span id="popupMessage"></span>
    <button class="popup-close">&times;</button>
</div>

<script>
    // password visibility toggle
    document.addEventListener("DOMContentLoaded", function() {
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword.addEventListener('click', function() {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
        this.classList.toggle('fa-eye');
        this.classList.toggle('fa-eye-slash');
    });
});

// Auto dismiss banner after 5 seconds
    document.addEventListener("DOMContentLoaded", function() {
        const banners = document.querySelectorAll('.success-banner, .error-banner');
        if (banners.length > 0) {
            setTimeout(() => {
                banners.forEach(banner => {
                    banner.classList.add('banner-hidden');
                });
            }, 5000); 
        }
    });


document.addEventListener("DOMContentLoaded", function() {
    const banners = document.querySelectorAll('.success-banner, .error-banner');

    banners.forEach(banner => {
        // Auto dismiss after 20 seconds
        let autoTimer = setTimeout(() => {
            banner.classList.add('banner-hidden');
        }, 20000);

        // Manual close (×)
        const closeBtn = banner.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                clearTimeout(autoTimer);      // stop auto timer
                // start fade out immediately
                banner.classList.add('banner-hidden');
            });
        }

        // Only remove from DOM when fadeOut animation completes
        banner.addEventListener('animationend', (e) => {
            // make sure it's the fadeOut animation, not slideDown
            if (e.animationName === 'fadeOut') {
                banner.remove();
            }
        });
    });
});

// Facebook toast
    const fbBtn = document.getElementById('facebookBtn');
    fbBtn.addEventListener('click', function() {
        showPopup('Facebook authentication coming soon!');
    });

        // Popup function
    function showPopup(message) {
        const popup = document.getElementById('popupToast');
        const popupMsg = document.getElementById('popupMessage');
        popupMsg.textContent = message;
        popup.classList.add('show');
        setTimeout(() => popup.classList.remove('show'), 4000);
    }

    // Close button
    document.querySelector('.popup-close').addEventListener('click', function() {
        document.getElementById('popupToast').classList.remove('show');
    });

</script>


</body>
</html>