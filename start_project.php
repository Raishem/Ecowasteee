<?php
session_start();
require_once 'config.php';

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get user data from database
$user_id = $_SESSION['user_id'];
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

try {
    $user_query = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $user_query->execute([$user_id]);
    $user_data = $user_query->fetch(PDO::FETCH_ASSOC);
    if (!$user_data) {
        $user_data = [];
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
    $project_name = isset($_POST['project_name']) ? trim($_POST['project_name']) : '';
    $project_description = isset($_POST['project_description']) ? trim($_POST['project_description']) : '';
    $materials = isset($_POST['materials']) ? (array)$_POST['materials'] : [];
    $quantities = isset($_POST['quantities']) ? (array)$_POST['quantities'] : [];
    
    // Validate inputs
    if (empty($project_name)) {
        $error_message = "Project name is required.";
    } elseif (empty($project_description)) {
        $error_message = "Project description is required.";
    } elseif (empty($materials) || empty($materials[0])) {
        $error_message = "At least one material is required.";
    } else {
        try {
            // Start transaction
            $conn->beginTransaction();
            
            // Insert project
            $project_stmt = $conn->prepare("INSERT INTO projects (user_id, project_name, description) VALUES (?, ?, ?)");
            if (!$project_stmt->execute([$user_id, $project_name, $project_description])) {
                throw new PDOException("Failed to create project");
            }
            
            $project_id = $conn->lastInsertId();
            
            // Insert materials
            $material_stmt = $conn->prepare("INSERT INTO project_materials (project_id, material_name, quantity) VALUES (?, ?, ?)");
            if (!$material_stmt) {
                throw new PDOException("Failed to prepare material statement");
            }
            
            foreach ($materials as $index => $material) {
                if (!empty($material) && isset($quantities[$index])) {
                    $quantity = (int)$quantities[$index];
                    if ($quantity > 0) {
                        if (!$material_stmt->execute([$project_id, $material, $quantity])) {
                            throw new PDOException("Failed to insert material");
                        }
                    }
                }
            }
            
            // Commit transaction
            $conn->commit();
            $success_message = "Project created successfully!";
            
            // Clear form
            $_POST = array();
            
        } catch (PDOException $e) {
            // Rollback transaction on error
            if ($conn) {
                $conn->rollBack();
            }
            $error_message = "Error creating project: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start Project | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/homepage.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            overflow: hidden;
            background-color: #3d6a06ff;
            color: white;
            font-weight: bold;
            font-size: 18px;
        }
        
        /* Project Form Styles */
        .back-button-container {
            margin-bottom: 20px;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            color: #2e8b57;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .back-button:hover {
            background-color: #f0f7e8;
        }
        
        .back-button i {
            margin-right: 8px;
        }
        
        .page-header {
            color: #2e8b57;
            margin-bottom: 30px;
            font-size: 28px;
        }
        
        .project-form {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            font-family: 'Open Sans', sans-serif;
        }
        
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .materials-list {
            margin-bottom: 15px;
        }
        
        .material-item {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            align-items: center;
        }
        
        .material-item input[type="text"] {
            flex: 2;
        }
        
        .material-item input[type="number"] {
            flex: 1;
        }
        
        .add-material {
            background-color: #f0f7e8;
            color: #2e8b57;
            border: 1px dashed #2e8b57;
            padding: 10px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .add-material:hover {
            background-color: #e0f0d8;
        }
        
        .add-material i {
            margin-right: 5px;
        }
        
        .remove-material {
            background-color: #ffebee;
            color: #f44336;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .remove-material:hover {
            background-color: #ffcdd2;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
            font-size: 16px;
        }
        
        .btn-secondary {
            background-color: #f5f5f5;
            color: #666;
        }
        
        .btn-secondary:hover {
            background-color: #e0e0e0;
        }
        
        .btn-primary {
            background-color: #2e8b57;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #3cb371;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        
        .alert-error {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
    </style>
</head>
<body>
    <header>
        <div class="logo-container">
            <div class="logo">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo">
            </div>
            <h1>EcoWaste</h1>
        </div>
        <div class="user-profile" id="userProfile">
            <div class="profile-pic">
                <?= strtoupper(substr(htmlspecialchars($user_data['first_name'] ?? 'User'), 0, 1)) ?>
            </div>
            <span class="profile-name"><?= htmlspecialchars($user_data['first_name'] ?? 'User') ?></span>
            <i class="fas fa-chevron-down dropdown-arrow"></i>
            <div class="profile-dropdown">
                <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
                <a href="#" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                <div class="dropdown-divider"></div>
                <a href="logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <aside class="sidebar">
            <nav>
                <ul>
                    <li><a href="homepage.php"><i class="fas fa-home"></i>Home</a></li>
                    <li><a href="browse.php"><i class="fas fa-search"></i>Browse</a></li>
                    <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php" style="color: rgb(4, 144, 4);"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>
        
        <main class="main-content">
            <div class="back-button-container">
                <a href="projects.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Projects
                </a>
            </div>
            
            <h2 class="page-header">Start a Recycling Project</h2>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="project-form" id="projectForm">
                <div class="form-group">
                    <label for="project-name">Project Name:</label>
                    <input type="text" id="project-name" name="project_name" placeholder="Enter project name (e.g. Plastic Bottle Vase)" 
                           value="<?= htmlspecialchars($_POST['project_name'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="project-description">Description:</label>
                    <textarea id="project-description" name="project_description" placeholder="Describe your project" required><?= htmlspecialchars($_POST['project_description'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Materials Needed</label>
                    <div class="materials-list" id="materials-list">
                        <?php if (isset($_POST['materials']) && is_array($_POST['materials'])): ?>
                            <?php foreach ($_POST['materials'] as $index => $material): ?>
                                <div class="material-item">
                                    <input type="text" name="materials[]" placeholder="Type of material" 
                                           value="<?= htmlspecialchars($material) ?>">
                                    <input type="number" name="quantities[]" placeholder="Quantity" min="1" 
                                           value="<?= htmlspecialchars($_POST['quantities'][$index] ?? '') ?>">
                                    <button type="button" class="btn btn-secondary remove-material">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="material-item">
                                <input type="text" name="materials[]" placeholder="Type of material">
                                <input type="number" name="quantities[]" placeholder="Quantity" min="1">
                                <button type="button" class="btn btn-secondary remove-material">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="add-material" id="add-material">
                        <i class="fas fa-plus"></i> Add Another Material
                    </button>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='projects.php'">Cancel</button>
                    <button type="submit" name="create_project" class="btn btn-primary">Create Project</button>
                </div>
            </form>
        </main>
    </div>
    
    <div class="feedback-btn" id="feedbackBtn">💬</div>
    <div class="feedback-modal" id="feedbackModal">
        <div class="feedback-content">
            <span class="feedback-close-btn" id="feedbackCloseBtn">&times;</span>
            <div class="feedback-form" id="feedbackForm">
                <h3>Share Your Feedback</h3>
                <div class="emoji-rating" id="emojiRating">
                    <div class="emoji-option" data-rating="1"><span class="emoji">😞</span><span class="emoji-label">Very Sad</span></div>
                    <div class="emoji-option" data-rating="2"><span class="emoji">😕</span><span class="emoji-label">Sad</span></div>
                    <div class="emoji-option" data-rating="3"><span class="emoji">😐</span><span class="emoji-label">Neutral</span></div>
                    <div class="emoji-option" data-rating="4"><span class="emoji">🙂</span><span class="emoji-label">Happy</span></div>
                    <div class="emoji-option" data-rating="5"><span class="emoji">😍</span><span class="emoji-label">Very Happy</span></div>
                </div>
                <div class="error-message" id="ratingError">Please select a rating</div>
                <p class="feedback-detail">Please share in detail what we can improve more?</p>
                <textarea id="feedbackText" placeholder="Your feedback helps us make EcoWaste better..."></textarea>
                <div class="error-message" id="textError">Please provide your feedback</div>
                <button type="submit" class="feedback-submit-btn" id="feedbackSubmitBtn">
                    Submit Feedback
                    <div class="spinner" id="spinner"></div>
                </button>
            </div>
            <div class="thank-you-message" id="thankYouMessage">
                <span class="thank-you-emoji">🎉</span>
                <h3>Thank You!</h3>
                <p>We appreciate your feedback and will use it to improve EcoWaste.</p>
                <p>Your opinion matters to us!</p>
            </div>
        </div>
    </div>
    
    <script>
        // User Profile Dropdown
        document.getElementById('userProfile').addEventListener('click', function() {
            this.classList.toggle('active');
        });
        
        document.addEventListener('click', function(event) {
            const userProfile = document.getElementById('userProfile');
            if (!userProfile.contains(event.target)) {
                userProfile.classList.remove('active');
            }
        });
        
        // Add Material Functionality
        document.getElementById('add-material').addEventListener('click', function() {
            const materialsList = document.getElementById('materials-list');
            const newMaterial = document.createElement('div');
            newMaterial.className = 'material-item';
            newMaterial.innerHTML = `
                <input type="text" name="materials[]" placeholder="Type of material">
                <input type="number" name="quantities[]" placeholder="Quantity" min="1">
                <button type="button" class="btn btn-secondary remove-material">
                    <i class="fas fa-times"></i>
                </button>
            `;
            materialsList.appendChild(newMaterial);
            
            // Add event listener to remove button
            newMaterial.querySelector('.remove-material').addEventListener('click', function() {
                materialsList.removeChild(newMaterial);
            });
        });
        
        // Add event listeners to existing remove buttons
        document.querySelectorAll('.remove-material').forEach(button => {
            button.addEventListener('click', function() {
                this.closest('.material-item').remove();
            });
        });
        
        // Form validation
        document.getElementById('projectForm').addEventListener('submit', function(e) {
            let isValid = true;
            const projectName = document.getElementById('project-name');
            const projectDescription = document.getElementById('project-description');
            const materialInputs = document.querySelectorAll('input[name="materials[]"]');
            
            // Validate project name
            if (!projectName.value.trim()) {
                isValid = false;
                projectName.style.borderColor = '#f44336';
            } else {
                projectName.style.borderColor = '#ddd';
            }
            
            // Validate project description
            if (!projectDescription.value.trim()) {
                isValid = false;
                projectDescription.style.borderColor = '#f44336';
            } else {
                projectDescription.style.borderColor = '#ddd';
            }
            
            // Validate at least one material
            let hasMaterial = false;
            materialInputs.forEach(input => {
                if (input.value.trim()) {
                    hasMaterial = true;
                    input.style.borderColor = '#ddd';
                } else {
                    input.style.borderColor = '#f44336';
                }
            });
            
            if (!hasMaterial) {
                isValid = false;
                alert('Please add at least one material.');
            }
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
        
        // Feedback system JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            const feedbackBtn = document.getElementById('feedbackBtn');
            const feedbackModal = document.getElementById('feedbackModal');
            const feedbackCloseBtn = document.getElementById('feedbackCloseBtn');
            const emojiOptions = document.querySelectorAll('.emoji-option');
            const feedbackForm = document.getElementById('feedbackForm');
            const thankYouMessage = document.getElementById('thankYouMessage');
            const feedbackSubmitBtn = document.getElementById('feedbackSubmitBtn');
            const spinner = document.getElementById('spinner');
            const ratingError = document.getElementById('ratingError');
            const textError = document.getElementById('textError');
            const feedbackText = document.getElementById('feedbackText');
            let selectedRating = 0;
            
            emojiOptions.forEach(option => {
                option.addEventListener('click', () => {
                    emojiOptions.forEach(opt => opt.classList.remove('selected'));
                    option.classList.add('selected');
                    selectedRating = option.getAttribute('data-rating');
                    ratingError.style.display = 'none';
                });
            });
            
            feedbackForm.addEventListener('submit', function(e) {
                e.preventDefault();
                let isValid = true;
                if (selectedRating === 0) {
                    ratingError.style.display = 'block';
                    isValid = false;
                } else {
                    ratingError.style.display = 'none';
                }
                if (feedbackText.value.trim() === '') {
                    textError.style.display = 'block';
                    isValid = false;
                } else {
                    textError.style.display = 'none';
                }
                if (!isValid) return;
                
                feedbackSubmitBtn.disabled = true;
                spinner.style.display = 'block';
                
                setTimeout(() => {
                    spinner.style.display = 'none';
                    feedbackForm.style.display = 'none';
                    thankYouMessage.style.display = 'block';
                    
                    setTimeout(() => {
                        feedbackModal.style.display = 'none';
                        feedbackForm.style.display = 'block';
                        thankYouMessage.style.display = 'none';
                        feedbackText.value = '';
                        emojiOptions.forEach(opt => opt.classList.remove('selected'));
                        selectedRating = 0;
                        feedbackSubmitBtn.disabled = false;
                    }, 3000);
                }, 1500);
            });
            
            feedbackBtn.addEventListener('click', () => {
                feedbackModal.style.display = 'flex';
            });
            
            feedbackCloseBtn.addEventListener('click', closeFeedbackModal);
            
            window.addEventListener('click', (event) => {
                if (event.target === feedbackModal) {
                    closeFeedbackModal();
                }
            });
            
            function closeFeedbackModal() {
                feedbackModal.style.display = 'none';
                feedbackForm.style.display = 'block';
                thankYouMessage.style.display = 'none';
                feedbackText.value = '';
                emojiOptions.forEach(opt => opt.classList.remove('selected'));
                selectedRating = 0;
                ratingError.style.display = 'none';
                textError.style.display = 'none';
                feedbackSubmitBtn.disabled = false;
                spinner.style.display = 'none';
            }
        });
    </script>
</body>
</html>