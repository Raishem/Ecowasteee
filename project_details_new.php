<?php
session_start();
require_once 'config.php';

// Initialize variables
$project = [];
$materials = [];
$steps = [];
$step_progress = [];
$success_message = '';
$error_message = '';
$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check login status
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

try {
    $conn = getDBConnection();
    
    // Get project details
    $stmt = $conn->prepare("SELECT * FROM projects WHERE project_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $project_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();

    if (!$project) {
        header('Location: projects.php');
        exit();
    }

    // Get project materials
    $materials_stmt = $conn->prepare("SELECT * FROM project_materials WHERE project_id = ?");
    $materials_stmt->bind_param("i", $project_id);
    $materials_stmt->execute();
    $materials = $materials_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get project steps
    $steps_stmt = $conn->prepare("SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_number");
    $steps_stmt->bind_param("i", $project_id);
    $steps_stmt->execute();
    $steps = $steps_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get step progress if tracking is enabled
    try {
        $prog_stmt = $conn->prepare("SELECT step_id, is_done FROM project_step_progress WHERE project_id = ?");
        if ($prog_stmt) {
            $prog_stmt->bind_param("i", $project_id);
            $prog_stmt->execute();
            $prog_res = $prog_stmt->get_result();
            while ($pr = $prog_res->fetch_assoc()) {
                $step_progress[(int)$pr['step_id']] = (bool)$pr['is_done'];
            }
        }
    } catch (mysqli_sql_exception $e) {
        // Table might not exist - leave $step_progress empty
    }
    
} catch (mysqli_sql_exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Get user data for header
try {
    $user_stmt = $conn->prepare("SELECT username, avatar FROM users WHERE user_id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
} catch (mysqli_sql_exception $e) {
    $user_data = ['username' => 'User', 'avatar' => ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/project-details-new.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Header -->
    <header>
        <div class="logo-container">
            <div class="logo">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo">
                <h1>EcoWaste</h1>
            </div>
        </div>
        <div class="header-actions">
            <div class="notifications-icon">
                <i class="fas fa-bell"></i>
                <span class="notification-badge">0</span>
            </div>
            <div class="user-profile" id="userProfile">
                <div class="profile-pic">
                    <?php if (!empty($user_data['avatar'])): ?>
                        <img src="<?= htmlspecialchars($user_data['avatar']) ?>" alt="User Avatar">
                    <?php else: ?>
                        <?= strtoupper(substr($user_data['username'] ?? 'U', 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <span class="profile-name"><?= htmlspecialchars($user_data['username'] ?? 'User') ?></span>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                <div class="profile-dropdown">
                    <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                    <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Left Sidebar -->
        <aside class="sidebar">
            <nav>
                <a href="homepage.php">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="browse.php">
                    <i class="fas fa-search"></i> Browse
                </a>
                <a href="projects.php" class="active">
                    <i class="fas fa-recycle"></i> My Projects
                </a>
                <a href="donations.php">
                    <i class="fas fa-hand-holding-heart"></i> Donations
                </a>
                <a href="achievements.php">
                    <i class="fas fa-trophy"></i> Achievements
                </a>
                <a href="leaderboard.php">
                    <i class="fas fa-crown"></i> Leaderboard
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="project-details">
            <div class="back-button">
                <a href="projects.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Projects
                </a>
            </div>

            <?php if ($success_message): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <div class="project-header">
                <h1 class="project-title"><?= htmlspecialchars($project['project_name']) ?></h1>
                <div class="project-description">
                    <?= nl2br(htmlspecialchars($project['description'])) ?>
                </div>
                
                <div class="action-buttons">
                    <button class="edit-btn" data-action="edit-project">
                        <i class="fas fa-edit"></i> Edit Project
                    </button>
                    <?php if (isset($project['status']) && $project['status'] === 'completed'): ?>
                        <button id="shareBtn" class="share-btn">
                            <i class="fas fa-share"></i> Share Project
                        </button>
                    <?php endif; ?>
                </div>
                
                <div class="project-meta">
                    <i class="far fa-calendar-alt"></i>
                    <span>Created: <?= date('M d, Y', strtotime($project['created_at'])) ?></span>
                </div>
            </div>

            <!-- Materials Section -->
            <div class="materials-section">
                <div class="materials-header">
                    <div class="materials-title-wrapper">
                        <i class="fas fa-box"></i>
                        <h2>Materials Needed</h2>
                    </div>
                    <button class="add-material-btn" data-action="open-add-material">
                        <i class="fas fa-plus"></i> Add Material
                    </button>
                </div>
                
                <div class="materials-grid">
                    <?php foreach ($materials as $material): ?>
                        <div class="material-card">
                            <div class="material-info">
                                <span class="material-name"><?= htmlspecialchars($material['name'] ?? $material['material_name'] ?? '') ?></span>
                                <span class="material-quantity">Quantity: <?= htmlspecialchars($material['quantity'] ?? '') ?></span>
                            </div>
                            <div class="material-status <?= $material['status'] ?? 'needed' ?>">
                                <?= ucfirst($material['status'] ?? 'needed') ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // User Profile Dropdown
        const userProfile = document.getElementById('userProfile');
        if (userProfile) {
            userProfile.addEventListener('click', function() {
                this.querySelector('.profile-dropdown').classList.toggle('show');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!userProfile.contains(e.target)) {
                    userProfile.querySelector('.profile-dropdown').classList.remove('show');
                }
            });
        }
    });
    </script>
</body>
</html>