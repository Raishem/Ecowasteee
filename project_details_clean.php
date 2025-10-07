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

    // Get user data for header
    $user_stmt = $conn->prepare("SELECT username, avatar FROM users WHERE user_id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
    
} catch (mysqli_sql_exception $e) {
    $error_message = "Database error: " . $e->getMessage();
    $user_data = ['username' => 'User', 'avatar' => ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details | EcoWaste</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/projects.css">
    <style>
        .project-details {
            background: white;
            border-radius: 8px;
            padding: 30px;
            margin-top: 20px;
        }

        .back-button {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #666;
            text-decoration: none;
            margin-bottom: 30px;
            font-size: 16px;
        }

        .back-button:hover {
            color: #82AA52;
        }

        .back-button i {
            font-size: 18px;
        }

        .project-header {
            margin-bottom: 30px;
        }

        .project-title-section {
            margin-bottom: 20px;
        }

        .section-label {
            display: block;
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .project-title {
            font-size: 24px;
            color: #333;
            margin: 0;
            margin-bottom: 15px;
        }

        .project-description {
            color: #666;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .project-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #666;
            font-size: 14px;
        }

        .edit-project {
            padding: 8px 16px;
            background: #82AA52;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: background-color 0.2s;
        }

        .edit-project:hover {
            background: #6b8d43;
        }

        .materials-section {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin-top: 30px;
        }

        .materials-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .materials-title {
            font-size: 18px;
            color: #333;
            margin: 0;
        }

        .materials-list {
            display: grid;
            gap: 15px;
        }

        .material-item {
            background: white;
            padding: 15px;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .add-material {
            padding: 8px 16px;
            background: #82AA52;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: background-color 0.2s;
        }

        .add-material:hover {
            background: #6b8d43;
        }
    </style>
</head>
<body>
    <header>
        <div class="logo-container">
            <img src="assets/img/ecowaste_logo.png" alt="EcoWaste">
            <h1>EcoWaste</h1>
        </div>
        <div class="user-profile" id="userProfile">
            <div class="profile-pic">
                <?php if (!empty($user_data['avatar'])): ?>
                    <img src="<?= htmlspecialchars($user_data['avatar']) ?>" alt="Profile Picture">
                <?php else: ?>
                    <?= strtoupper(substr($user_data['username'] ?? 'U', 0, 1)) ?>
                <?php endif; ?>
            </div>
            <span class="profile-name"><?= htmlspecialchars($user_data['username'] ?? 'User') ?></span>
            <i class="fas fa-chevron-down"></i>
            <div class="profile-dropdown">
                <a href="profile.php" class="dropdown-item">
                    <i class="fas fa-user"></i>
                    Profile
                </a>
                <a href="settings.php" class="dropdown-item">
                    <i class="fas fa-cog"></i>
                    Settings
                </a>
                <a href="logout.php" class="dropdown-item">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>
    </header>

    <div class="container">
        <aside class="sidebar">
            <nav class="side-navigation">
                <a href="homepage.php" class="nav-item">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
                <a href="browse.php" class="nav-item">
                    <i class="fas fa-search"></i>
                    <span>Browse</span>
                </a>
                <a href="projects.php" class="nav-item active">
                    <i class="fas fa-recycle"></i>
                    <span>My Projects</span>
                </a>
                <a href="donations.php" class="nav-item">
                    <i class="fas fa-hand-holding-heart"></i>
                    <span>Donations</span>
                </a>
                <a href="achievements.php" class="nav-item">
                    <i class="fas fa-trophy"></i>
                    <span>Achievements</span>
                </a>
            </nav>
        </aside>

        <main class="project-details">
            <a href="projects.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Back to Projects
            </a>

            <div class="project-header">
                <div class="project-title-section">
                    <span class="section-label">Title</span>
                    <h1 class="project-title"><?= htmlspecialchars($project['project_name']) ?></h1>
                </div>

                <div class="project-description-section">
                    <span class="section-label">Description</span>
                    <p class="project-description"><?= htmlspecialchars($project['description']) ?></p>
                </div>

                <div class="project-meta">
                    <span>
                        <i class="far fa-calendar-alt"></i>
                        Created: <?= date('M d, Y', strtotime($project['created_at'])) ?>
                    </span>

                    <button class="edit-project" data-action="edit-project">
                        <i class="fas fa-edit"></i>
                        Edit Project
                    </button>
                </div>
            </div>

            <div class="materials-section">
                <div class="materials-header">
                    <h2 class="materials-title">Materials</h2>
                    <button class="add-material" onclick="showAddMaterialForm()">
                        <i class="fas fa-plus"></i>
                        Add Material
                    </button>
                </div>

                <div class="materials-list">
                    <?php foreach ($materials as $material): ?>
                        <div class="material-item">
                            <div class="material-info">
                                <span class="material-name"><?= htmlspecialchars($material['material_name']) ?></span>
                                <span class="material-quantity">Quantity: <?= htmlspecialchars($material['quantity']) ?></span>
                            </div>
                            <div class="material-status">
                                <?= htmlspecialchars($material['status']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>

    <?php include __DIR__ . '/includes/share_modal.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const userProfile = document.getElementById('userProfile');
            
            userProfile.addEventListener('click', function(e) {
                e.preventDefault();
                this.classList.toggle('active');
            });

            document.addEventListener('click', function(e) {
                if (!userProfile.contains(e.target)) {
                    userProfile.classList.remove('active');
                }
            });
        });

        function showAddMaterialForm() {
            // Implementation for adding materials
        }
    </script>
</body>
</html>