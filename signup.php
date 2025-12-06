<?php
session_start();
require_once "config.php";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize variables
$show_success_modal = false;
$error_message = '';

// Check if user came from Google OAuth
$google_data = $_SESSION['google_signup_data'] ?? null;
$signup_message = $_SESSION['signup_message'] ?? '';
// Clear the message after retrieving it
if (isset($_SESSION['signup_message'])) {
    unset($_SESSION['signup_message']);
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Get and sanitize form data
    $firstName = htmlspecialchars($_POST['first-name'] ?? '');
    $middleName = htmlspecialchars($_POST['middle-name'] ?? '');
    $lastName = htmlspecialchars($_POST['last-name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $contactNumber = htmlspecialchars($_POST['contact-number'] ?? '');
    $address = htmlspecialchars($_POST['address'] ?? '');
    $zipCode = htmlspecialchars($_POST['zip-code'] ?? '');
    $city = htmlspecialchars($_POST['city'] ?? ''); // Added city field

    // Check if this is a Google signup
    $isGoogleSignup = isset($_SESSION['google_signup_data']);

    // Validate input
    if (
        empty($firstName) || empty($lastName) || empty($email) ||
        empty($contactNumber) || empty($address) || empty($zipCode)
    ) {
        $error_message = "All required fields must be filled";
    } elseif (!$isGoogleSignup && empty($password)) {
        // Password is only required for non-Google signups
        $error_message = "Password is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format";
    } elseif (!empty($password) && $password !== ($_POST['confirm-password'] ?? '')) {
        // Only validate password match if password was provided
        $error_message = "Passwords do not match";
    } elseif (!empty($password) && strlen($password) < 8) {
        // Only validate password length if password was provided
        $error_message = "Password must be at least 8 characters";
    } else {
        try {
            // Hash password (or set to NULL for Google-only accounts)
            $passwordHash = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
            $conn = getDBConnection();

            // Check if email exists
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $error_message = "Email already registered";
                $_SESSION['signup_error'] = "Email already registered";
            } else {
                // Check if user came from Google signup
                $google_signup = $_SESSION['google_signup_data'] ?? null;

                if ($google_signup) {
                    // Insert new user with Google data
                    $stmt = $conn->prepare("INSERT INTO users 
                        (email, password_hash, first_name, middle_name, last_name, 
                        contact_number, address, zip_code, city, google_id, avatar) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    if (false === $stmt) {
                        die("Prepare failed: " . $conn->error);
                    }

                    $bindResult = $stmt->bind_param(
                        "sssssssssss",
                        $email,
                        $passwordHash,
                        $firstName,
                        $middleName,
                        $lastName,
                        $contactNumber,
                        $address,
                        $zipCode,
                        $city,
                        $google_signup['google_id'],
                        $google_signup['avatar']
                    );
                } else {
                    // Regular signup without Google
                    $stmt = $conn->prepare("INSERT INTO users 
                        (email, password_hash, first_name, middle_name, last_name, 
                        contact_number, address, zip_code, city) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    if (false === $stmt) {
                        die("Prepare failed: " . $conn->error);
                    }

                    $bindResult = $stmt->bind_param(
                        "sssssssss",
                        $email,
                        $passwordHash,
                        $firstName,
                        $middleName,
                        $lastName,
                        $contactNumber,
                        $address,
                        $zipCode,
                        $city
                    );
                }

                if (false === $bindResult) {
                    die("Bind failed: " . $stmt->error);
                }

                if ($stmt->execute()) {
                    $user_id = $stmt->insert_id;
                    
                    // Check if this was a Google signup
                    if (isset($_SESSION['google_signup_data'])) {
                        // Auto-login for Google signups
                        $google_signup = $_SESSION['google_signup_data'];
                        
                        // Generate remember token
                        $remember_token = bin2hex(random_bytes(32));
                        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                        
                        // Update user record with remember token
                        $update_stmt = $conn->prepare("UPDATE users SET remember_token=?, token_expiry=? WHERE user_id=?");
                        $update_stmt->bind_param("ssi", $remember_token, $expiry, $user_id);
                        $update_stmt->execute();
                        
                        // Set cookie
                        setcookie('remember_token', $remember_token, time() + (30 * 24 * 60 * 60), "/"); // 30 days
                        
                        // Set session variables for login
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['user_name'] = $google_signup['name'];
                        $_SESSION['first_name'] = $firstName;
                        $_SESSION['last_name'] = $lastName;
                        $_SESSION['logged_in'] = true;
                        
                        // Clear Google signup data
                        unset($_SESSION['google_signup_data']);
                        
                        // Redirect to homepage (auto-login)
                        header("Location: homepage.php");
                        exit();
                    } else {
                        // Regular signup - show success modal
                        $_SESSION['new_user_email'] = $email;
                        $_SESSION['signup_success'] = "Account created successfully! You can now log in.";
                        $show_success_modal = true;
                    }
                } else {
                    $error_message = "Execute failed: " . $stmt->error;
                    error_log("Database error: " . $stmt->error);
                }
            }
            $stmt->close();
            $conn->close();
        } catch (Exception $e) {
            error_log("Database error: " . $e->getMessage());
            $error_message = "A system error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign up for EcoWaste</title>
    <link rel="stylesheet" href="assets/css/signup.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap"
        rel="stylesheet">
    <style>
        .error-banner {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #ff4444;
            color: white;
            padding: 15px 25px;
            border-radius: 5px;
            z-index: 1000;
            animation: fadeIn 0.3s;
        }

        .success-banner {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #4CAF50;
            color: white;
            padding: 15px 25px;
            border-radius: 5px;
            z-index: 1000;
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                top: 0;
            }

            to {
                opacity: 1;
                top: 20px;
            }
        }
    </style>
</head>

<body>
    <header class="main-header">
        <div class="logo-image">
            <a href="index.php">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo" class="logo-img">
            </a>
        </div>
    </header>

    <div class="signup-container" id="signupContainer" style="<?= $show_success_modal ? 'display:none' : '' ?>">
        <div class="left-section">
            <div class="curved-design">
                <div class="curve curve-large"></div>
                <div class="curve curve-medium"></div>
                <div class="curve curve-small"></div>
            </div>
            <div class="content-container">

                <?php if (isset($_SESSION['signup_error'])): ?>
                    <div class="error-banner">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= $_SESSION['signup_error']; ?>
                    </div>
                    <?php unset($_SESSION['signup_error']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['signup_success'])): ?>
                    <div class="success-banner">
                        <i class="fas fa-check-circle"></i>
                        <?= $_SESSION['signup_success']; ?>
                    </div>
                    <?php unset($_SESSION['signup_success']); ?>
                <?php endif; ?>

                <?php if (!empty($signup_message)): ?>
                    <div class="success-banner" style="background: #2196F3;">
                        <i class="fas fa-info-circle"></i>
                        <?= htmlspecialchars($signup_message); ?> No password needed - you'll log in with Google.
                    </div>
                <?php endif; ?>


                <h1>Get Started Now</h1>
                <p class="subtitle">Create your account to start donating waste sustainably.</p>

                <form id="signup-form" method="POST" onsubmit="return true;">
                    <div class="form-grid">
                        <div class="form-group full-name-container">
                            <label for="full-name" class="required">Full Name</label>
                            <input type="text" id="full-name" name="full-name" class="full-name-input" required
                                placeholder="Enter Full Name" readonly onclick="toggleNameDetails()">

                            <div class="name-details" id="name-details">
                                <div class="form-group">
                                    <label for="first-name" class="required">First Name</label>
                                    <input type="text" id="first-name" name="first-name" required
                                        value="<?= htmlspecialchars($firstName ?? $google_data['first_name'] ?? '') ?>"
                                        placeholder="Enter First Name">
                                </div>
                                <div class="form-group">
                                    <label for="middle-name">Middle Name</label>
                                    <input type="text" id="middle-name" name="middle-name"
                                        value="<?= htmlspecialchars($middleName ?? '') ?>"
                                        placeholder="Enter Middle Name">
                                </div>
                                <div class="form-group">
                                    <label for="last-name" class="required">Last Name</label>
                                    <input type="text" id="last-name" name="last-name" required
                                        value="<?= htmlspecialchars($lastName ?? $google_data['last_name'] ?? '') ?>"
                                        placeholder="Enter Last Name">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="contact-number" class="required">Contact Number</label>
                            <input type="tel" id="contact-number" name="contact-number" required
                                value="<?= htmlspecialchars($contactNumber ?? '') ?>"
                                placeholder="Enter Contact Number">
                        </div>

                        <div class="form-group">
                            <label for="email" class="required">Email address</label>
                            <input type="email" id="email" name="email" required
                                value="<?= htmlspecialchars($email ?? $google_data['email'] ?? '') ?>"
                                placeholder="email@gmail.com" <?= isset($google_data['email']) ? 'readonly style="background-color: #f5f5f5;"' : '' ?>>
                        </div>

                        <div class="form-group">
                            <label for="address" class="required">Full Address</label>
                            <input type="text" id="address" name="address" required
                                value="<?= htmlspecialchars($address ?? '') ?>" placeholder="Enter Full Address">
                        </div>

                        <div class="form-group">
                            <label for="city" class="required">City</label>
                            <input type="text" id="city" name="city" required
                                value="<?= htmlspecialchars($city ?? '') ?>" placeholder="Enter City">
                        </div>

                        <div class="form-group" id="password-group" <?= $google_data ? 'style="display: none;"' : '' ?>>
                            <label for="password" class="<?= $google_data ? '' : 'required' ?>">
                                Password <?= $google_data ? '(Optional - for traditional login)' : '' ?>
                            </label>
                            <input type="password" id="password" name="password" 
                                <?= $google_data ? '' : 'required' ?> minlength="8"
                                placeholder="<?= $google_data ? 'Optional: Set password for traditional login' : 'Enter Password' ?>">
                            <?php if ($google_data): ?>
                                <small style="color: #666; font-size: 0.85em; display: block; margin-top: 5px;">
                                    You can log in with Google. Set a password only if you also want to use traditional login.
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="zip-code" class="required">Zip Code</label>
                            <input type="text" id="zip-code" name="zip-code" required
                                value="<?= htmlspecialchars($zipCode ?? '') ?>" placeholder="Enter Zip Code">
                        </div>

                        <div class="form-group" id="confirm-password-group" <?= $google_data ? 'style="display: none;"' : '' ?>>
                            <label for="confirm-password" class="<?= $google_data ? '' : 'required' ?>">
                                Confirm Password <?= $google_data ? '(Optional)' : '' ?>
                            </label>
                            <input type="password" id="confirm-password" name="confirm-password" 
                                <?= $google_data ? '' : 'required' ?>
                                placeholder="<?= $google_data ? 'Optional: Confirm password' : 'Re-enter Password' ?>">
                        </div>
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" id="terms" name="terms" required>
                        <label for="terms">I agree to the <a href="terms.php" style="text-decoration: underline;">terms
                                & policy</a>.</label>
                    </div>

                    <button type="submit" class="signup-btn">Sign Up</button>

                    <div class="divider">or</div>

                    <div class="social-login">
                        <a href="google_login.php" class="social-btn google">
                            <i class="fab fa-google"></i> Sign up with Google
                        </a>
                        <button type="button" class="social-btn facebook" id="facebookBtnSignup">
                            <i class="fab fa-facebook-f"></i> (Coming Soon)
                        </button>
                    </div>

                    <p class="login-link">Already have an account? <a href="login.php">Log in</a></p>
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

    <!-- Success Modal -->
    <div class="success-modal" id="successModal" style="display: <?= $show_success_modal ? 'flex' : 'none' ?>;">
        <div class="modal-content">
            <i class="fas fa-check-circle"></i>
            <h2>Successfully Signed Up!</h2>
            <p>Your account <strong><?= htmlspecialchars($_SESSION['new_user_email'] ?? '') ?></strong> has been
                created.</p>
            <button onclick="window.location.href='login.php'">Continue to Login</button>
        </div>
    </div>

    <!-- Error Display -->
    <?php if (!empty($error_message)): ?>
        <div class="error-banner">
            <?= $error_message ?>
            <button onclick="this.parentElement.remove()"
                style="margin-left: 15px; background: none; border: none; color: white;">×</button>
        </div>
    <?php endif; ?>


    <script>
        // Name field handling
        function toggleNameDetails() {
            const nameDetails = document.getElementById('name-details');
            nameDetails.classList.toggle('active');
        }

        // Auto-update full name
        document.querySelectorAll('#first-name, #middle-name, #last-name').forEach(input => {
            input.addEventListener('input', updateFullName);
        });

        function updateFullName() {
            const firstName = document.getElementById('first-name').value;
            const middleName = document.getElementById('middle-name').value;
            const lastName = document.getElementById('last-name').value;
            document.getElementById('full-name').value =
                [firstName, middleName, lastName].filter(Boolean).join(' ');
        }

        // Form validation
        document.getElementById('signup-form').addEventListener('submit', function (e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm-password').value;

            // Only validate password match if a password was entered
            if (password && password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                document.getElementById('confirm-password').focus();
                return;
            }

            if (!document.getElementById('terms').checked) {
                e.preventDefault();
                alert('You must accept the terms and conditions');
            }
        });

        <?php if ($show_success_modal): ?>
            document.getElementById('successModal').style.display = 'flex';
            document.getElementById('success-email').textContent = '<?= $_SESSION['new_user_email'] ?>';
        <?php endif; ?>


        // Popup toast function
        function showPopup(message) {
            const popup = document.getElementById('popupToast');
            const popupMsg = document.getElementById('popupMessage');
            popupMsg.textContent = message;
            popup.classList.add('show');
            setTimeout(() => popup.classList.remove('show'), 4000);
        }
        document.querySelector('.popup-close').addEventListener('click', function () {
            document.getElementById('popupToast').classList.remove('show');
        });

        // Facebook button click
        document.getElementById('facebookBtnSignup').addEventListener('click', function () {
            showPopup('Facebook authentication coming soon!');
        });
    </script>
</body>

</html>