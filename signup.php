<?php
require_once "config.php";
$conn = getDBConnection();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);

    // Check if email exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $_SESSION['signup_error'] = "Email already exists. Please use another one.";
        header("Location: signup.php");
        exit();
    }

    // If not exists → continue inserting new user
    $password = password_hash($_POST["password"], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $_POST["first_name"], $_POST["last_name"], $email, $password);
    $stmt->execute();

    $_SESSION['signup_success'] = "Account created successfully! You can now log in.";
    header("Location: login.php");
    exit();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize variables
$show_success_modal = false;
$error_message = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config.php';
    

    // Get and sanitize form data
    $firstName = htmlspecialchars($_POST['first-name'] ?? '');
    $middleName = htmlspecialchars($_POST['middle-name'] ?? '');
    $lastName = htmlspecialchars($_POST['last-name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $contactNumber = htmlspecialchars($_POST['contact-number'] ?? '');
    $address = htmlspecialchars($_POST['address'] ?? '');
    $zipCode = htmlspecialchars($_POST['zip-code'] ?? '');

    // Validate input
    if (empty($firstName) || empty($lastName) || empty($email) || empty($password) || 
        empty($contactNumber) || empty($address) || empty($zipCode)) {
        $error_message = "All required fields must be filled";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format";
    } elseif ($password !== ($_POST['confirm-password'] ?? '')) {
        $error_message = "Passwords do not match";
    } elseif (strlen($password) < 8) {
        $error_message = "Password must be at least 8 characters";
    } else {
        try {
            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $conn = getDBConnection();
            
            // Check if email exists
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $error_message = "Email already registered";
            } else {
                // Insert new user
$stmt = $conn->prepare("INSERT INTO users 
    (email, password_hash, first_name, middle_name, last_name, 
    contact_number, address, city, zip_code) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

if (false === $stmt) {
    die("Prepare failed: " . $conn->error);
}

$bindResult = $stmt->bind_param("sssssssss", 
    $email, $passwordHash, $firstName, $middleName, $lastName,
    $contactNumber, $address, $zipCode);

if (false === $bindResult) {
    die("Bind failed: " . $stmt->error);
}

if ($stmt->execute()) {
    $_SESSION['new_user_email'] = $email;
    $show_success_modal = true;
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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
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
        @keyframes fadeIn {
            from { opacity: 0; top: 0; }
            to { opacity: 1; top: 20px; }
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

                <h1>Get Started Now</h1>
                <p class="subtitle">Create your account to start donating waste sustainably.</p>
                
                <form id="signup-form" method="POST" onsubmit="return true;">
                    <div class="form-grid">
                        <div class="form-group full-name-container">
                            <label for="full-name" class="required">Full Name</label>
                            <input type="text" id="full-name" name="full-name" class="full-name-input" required
                                placeholder="Enter Full Name" readonly
                                onclick="toggleNameDetails()">
                            
                            <div class="name-details" id="name-details">
                                <div class="form-group">
                                    <label for="first-name" class="required">First Name</label>
                                    <input type="text" id="first-name" name="first-name" required 
                                        value="<?= htmlspecialchars($firstName ?? '') ?>" 
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
                                        value="<?= htmlspecialchars($lastName ?? '') ?>" 
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
                                value="<?= htmlspecialchars($email ?? '') ?>" 
                                placeholder="email@gmail.com">
                        </div>
                        
                        <div class="form-group">
                            <label for="address" class="required">Full Address</label>
                            <input type="text" id="address" name="address" required
                                value="<?= htmlspecialchars($address ?? '') ?>" 
                                placeholder="Enter Full Address">
                        </div>
                        
                        <div class="form-group">
                            <label for="password" class="required">Password</label>
                            <input type="password" id="password" name="password" required minlength="8" 
                                placeholder="Enter Password">
                        </div>
                        
                        <div class="form-group">
                            <label for="zip-code" class="required">Zip Code</label>
                            <input type="text" id="zip-code" name="zip-code" required
                                value="<?= htmlspecialchars($zipCode ?? '') ?>" 
                                placeholder="Enter Zip Code">
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm-password" class="required">Confirm Password</label>
                            <input type="password" id="confirm-password" name="confirm-password" required 
                                placeholder="Re-enter Password">
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="terms" name="terms" required>
                        <label for="terms">I agree to the <a href="terms.php" style="text-decoration: underline;">terms & policy</a>.</label>
                    </div>
                    
                    <button type="submit" class="signup-btn">Sign Up</button>
                    
                    <div class="divider">or</div>
                    
                    <div class="social-login">
                        <button type="button" class="social-btn google">
                            <i class="fab fa-google"></i> Sign up with Google
                        </button>
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
            <p>Your account <strong><?= htmlspecialchars($_SESSION['new_user_email'] ?? '') ?></strong> has been created.</p>
            <button onclick="window.location.href='login.php'">Continue to Login</button>
        </div>
    </div>

    <!-- Error Display -->
    <?php if (!empty($error_message)): ?>
        <div class="error-banner">
            <?= $error_message ?>
            <button onclick="this.parentElement.remove()" style="margin-left: 15px; background: none; border: none; color: white;">×</button>
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
        document.getElementById('signup-form').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm-password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                document.getElementById('confirm-password').focus();
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
document.querySelector('.popup-close').addEventListener('click', function() {
    document.getElementById('popupToast').classList.remove('show');
});

// Facebook button click
document.getElementById('facebookBtnSignup').addEventListener('click', function() {
    showPopup('Facebook authentication coming soon!');
});
    </script>
</body>
</html>