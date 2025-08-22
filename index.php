<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>  
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to EcoWaste</title>
    <link rel="stylesheet" href="assets/css/index.css">
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

    <div class="signup-container">
        <div class="left-section">
            <div class="content-container">
                <h1 class="trash-text">One man's <span class="highlight">TRASH</span></h1>
                <h1 class="treasure-text">is another man's <span class="highlight">TREASURE</span></h1>

                <div class="start-section">
                    <a href="login.php" class="start-button">Get Started</a>
                </div>
            </div>
        </div>

    </div>
</body>
</html>