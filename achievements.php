<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch stats
$stats = $conn->query("SELECT * FROM user_stats WHERE user_id = $user_id")->fetch_assoc();

// Calculate level and progress
$total_points = ($stats['items_recycled'] ?? 0) + ($stats['items_donated'] ?? 0) * 2;
$level = floor($total_points / 25); // 25 points per level
$current_level_points = $total_points % 25;
$progress_percentage = ($current_level_points / 25) * 100;

// Fetch tasks
$tasks = [];
$result = $conn->query("SELECT * FROM user_tasks WHERE user_id = $user_id");
while ($row = $result->fetch_assoc()) $tasks[] = $row;

// If no tasks exist, create default tasks for the user
if (empty($tasks)) {
    $default_tasks = [
        // Donation-related tasks
        [
            'title' => 'Rising Donor',
            'description' => 'Complete your first donation',
            'reward' => '20 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 20,
            'progress' => '0/1',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 1,
            'action_type' => 'items_donated'
        ],
        [
            'title' => 'Helpful Friend',
            'description' => 'Complete 10 donations',
            'reward' => '50 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 50,
            'progress' => '0/10',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 10,
            'action_type' => 'items_donated'
        ],
        [
            'title' => 'Care Giver',
            'description' => 'Complete 15 donations',
            'reward' => '100 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 100,
            'progress' => '0/15',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 15,
            'action_type' => 'items_donated'
        ],
        [
            'title' => 'Generous Giver',
            'description' => 'Complete 20 donations',
            'reward' => '150 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 150,
            'progress' => '0/20',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 20,
            'action_type' => 'items_donated'
        ],
        [
            'title' => 'Community Helper',
            'description' => 'Complete 25 donations',
            'reward' => '200 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 200,
            'progress' => '0/25',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 25,
            'action_type' => 'items_donated'
        ],
        [
            'title' => 'Charity Champion',
            'description' => 'Complete 30 donations',
            'reward' => '250 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 250,
            'progress' => '0/30',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 30,
            'action_type' => 'items_donated'
        ],
        
        // Project creation tasks
        [
            'title' => 'Eco Beginner',
            'description' => 'Start your first recycling project',
            'reward' => '20 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 20,
            'progress' => '0/1',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 1,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Builder',
            'description' => 'Create 10 recycling projects',
            'reward' => '50 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 50,
            'progress' => '0/10',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 10,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Nature Keeper',
            'description' => 'Create 15 recycling projects',
            'reward' => '100 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 100,
            'progress' => '0/15',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 15,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Conservation Expert',
            'description' => 'Create 20 recycling projects',
            'reward' => '150 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 150,
            'progress' => '0/20',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 20,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Zero Waste Hero',
            'description' => 'Create 25 recycling projects',
            'reward' => '200 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 200,
            'progress' => '0/25',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 25,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Earth Saver',
            'description' => 'Create 30 recycling projects',
            'reward' => '250 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 250,
            'progress' => '0/30',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 30,
            'action_type' => 'projects_completed'
        ],
        
        // Recycling project completion tasks
        [
            'title' => 'Eco Star',
            'description' => 'Complete a recycling project',
            'reward' => '20 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 20,
            'progress' => '0/1',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 1,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Warrior',
            'description' => 'Complete 10 recycling projects',
            'reward' => '50 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 50,
            'progress' => '0/10',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 10,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Elite',
            'description' => 'Complete 15 recycling projects',
            'reward' => '100 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 100,
            'progress' => '0/15',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 15,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Pro',
            'description' => 'Complete 20 recycling projects',
            'reward' => '150 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 150,
            'progress' => '0/20',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 20,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Master',
            'description' => 'Complete 25 recycling projects',
            'reward' => '200 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 200,
            'progress' => '0/25',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 25,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Legend',
            'description' => 'Complete 30 recycling projects',
            'reward' => '250 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 250,
            'progress' => '0/30',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 30,
            'action_type' => 'projects_completed'
        ]
    ];
    
    foreach ($default_tasks as $task) {
        $title = $conn->real_escape_string($task['title']);
        $description = $conn->real_escape_string($task['description']);
        $reward = $conn->real_escape_string($task['reward']);
        $reward_type = $conn->real_escape_string($task['reward_type']);
        $reward_value = $task['reward_value'];
        $progress = $conn->real_escape_string($task['progress']);
        $status = $conn->real_escape_string($task['status']);
        $current = $task['current'];
        $target = $task['target'];
        $action_type = $conn->real_escape_string($task['action_type']);
        
        // Check if action_type column exists before trying to insert it
        $columns = "user_id, title, description, reward, progress, status, current_value, target_value";
        $values = "$user_id, '$title', '$description', '$reward', '$progress', '$status', $current, $target";
        
        // Add reward_type and reward_value if they exist in the table
        $column_check = $conn->query("SHOW COLUMNS FROM user_tasks LIKE 'reward_type'");
        if ($column_check->num_rows > 0) {
            $columns .= ", reward_type, reward_value";
            $values .= ", '$reward_type', $reward_value";
        }
        
        // Add action_type if it exists in the table
        $column_check = $conn->query("SHOW COLUMNS FROM user_tasks LIKE 'action_type'");
        if ($column_check->num_rows > 0) {
            $columns .= ", action_type";
            $values .= ", '$action_type'";
        }
        
        $conn->query("INSERT INTO user_tasks ($columns) VALUES ($values)");
    }
    
    // Reload tasks
    $result = $conn->query("SELECT * FROM user_tasks WHERE user_id = $user_id");
    while ($row = $result->fetch_assoc()) $tasks[] = $row;
}

// Handle task completion and reward redemption
if (isset($_POST['redeem_reward']) && isset($_POST['task_id'])) {
    $task_id = intval($_POST['task_id']);
    
    // Get task details
    $task_result = $conn->query("SELECT * FROM user_tasks WHERE id = $task_id AND user_id = $user_id");
    if ($task_result->num_rows > 0) {
        $task = $task_result->fetch_assoc();
        
        if ($task['status'] === 'Completed' && (isset($task['reward_claimed']) ? $task['reward_claimed'] == 0 : true)) {
            // Check if reward_claimed column exists
            $reward_claimed_column = $conn->query("SHOW COLUMNS FROM user_tasks LIKE 'reward_claimed'");
            $has_reward_claimed = $reward_claimed_column->num_rows > 0;
            
            // Apply reward based on type if the columns exist
            $reward_type_column = $conn->query("SHOW COLUMNS FROM user_tasks LIKE 'reward_type'");
            if ($reward_type_column->num_rows > 0 && isset($task['reward_type'])) {
                if ($task['reward_type'] === 'points' && isset($task['reward_value'])) {
                    // Add points to user's total
                    $points = $task['reward_value'];
                    $conn->query("UPDATE user_stats SET total_points = total_points + $points WHERE user_id = $user_id");
                    
                    // Update achievements earned
                    $conn->query("UPDATE user_stats SET achievements_earned = achievements_earned + 1 WHERE user_id = $user_id");
                } elseif ($task['reward_type'] === 'badge') {
                    // Add badge to user's collection
                    $conn->query("UPDATE user_stats SET badges_earned = badges_earned + 1 WHERE user_id = $user_id");
                    
                    // Update achievements earned
                    $conn->query("UPDATE user_stats SET achievements_earned = achievements_earned + 1 WHERE user_id = $user_id");
                }
            }
            
            // Mark reward as claimed if the column exists
            if ($has_reward_claimed) {
                $conn->query("UPDATE user_tasks SET reward_claimed = 1 WHERE id = $task_id");
            }
            
            $_SESSION['success_message'] = "Reward redeemed successfully!";
        }
    }
    
    // Refresh page to show updated stats
    header("Location: achievements.php");
    exit();
}

// Update task progress based on user stats
foreach ($tasks as $task) {
    if ($task['status'] !== 'Completed') {
        // Check if action_type column exists and is set
        $action_type_column = $conn->query("SHOW COLUMNS FROM user_tasks LIKE 'action_type'");
        if ($action_type_column->num_rows > 0 && isset($task['action_type'])) {
            $action_type = $task['action_type'];
            $current_value = $stats[$action_type] ?? 0;
        } else {
            // Default behavior if action_type doesn't exist
            $current_value = 0;
        }
        
        $target_value = $task['target_value'];
        
        // Don't exceed target value
        if ($current_value > $target_value) {
            $current_value = $target_value;
        }
        
        // Update progress
        $progress = "$current_value/$target_value";
        $status = ($current_value >= $target_value) ? 'Completed' : 'In Progress';
        
        // Make sure the task has an ID
        if (isset($task['id'])) {
            $conn->query("UPDATE user_tasks 
                         SET current_value = $current_value, progress = '$progress', status = '$status' 
                         WHERE id = {$task['id']} AND user_id = $user_id");
        }
    }
}

// Reload tasks after updating progress
$tasks = [];
$result = $conn->query("SELECT * FROM user_tasks WHERE user_id = $user_id");
while ($row = $result->fetch_assoc()) $tasks[] = $row;

// Reload stats after potential updates
$stats = $conn->query("SELECT * FROM user_stats WHERE user_id = $user_id")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achievements | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/achievement.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
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

.success-message {
    background-color: #d4edda;
    color: #155724;
    padding: 12px 20px;
    border-radius: 5px;
    margin-bottom: 20px;
    border-left: 4px solid #28a745;
}

.redeem-form {
    margin-top: 15px;
}

.reward-claimed {
    margin-top: 15px;
    color: #28a745;
    font-weight: bold;
    display: flex;
    align-items: center;
    gap: 8px;
}
</style>

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
        <?= strtoupper(substr(htmlspecialchars($_SESSION['first_name'] ?? 'User'), 0, 1)) ?>
    </div>
    <span class="profile-name"><?= htmlspecialchars($_SESSION['first_name'] ?? 'User') ?></span>
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
                    <li><a href="achievements.php" style="color: rgb(4, 144, 4);"><i class="fas fa-star"></i>Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>
        <main class="main-content">
            <div class="achievements-header">
                <h2>My Achievements</h2>
                <p class="subtitle">Track your eco-friendly progress and accomplishments</p>
            </div>
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="success-message">
                    <?= $_SESSION['success_message'] ?>
                    <?php unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            
            <div class="achievements-content">
                <!-- Level Card with Circular Progress -->
                <div class="level-card">
                    <div class="circular-progress">
                        <svg class="progress-ring" width="200" height="200">
                            <circle class="progress-ring-circle" stroke="#e0e0e0" stroke-width="10" fill="transparent" r="90" cx="100" cy="100"/>
                            <circle class="progress-ring-progress" stroke="#82AA52" stroke-width="10" fill="transparent" r="90" cx="100" cy="100" 
                                    stroke-dasharray="565.48" stroke-dashoffset="<?= 565.48 - (565.48 * $progress_percentage / 100) ?>"/>
                        </svg>
                        <div class="circle">
                            <div class="circle-inner">
                                <div class="level-number"><?= $level ?></div>
                                <div class="level-label">LEVEL</div>
                            </div>
                        </div>
                    </div>
                    <div class="progress-text"><?= $current_level_points ?>/25 pts</div>
                    <div class="current-level">Progress to Level <?= $level + 1 ?></div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?= htmlspecialchars($stats['projects_completed'] ?? 0) ?></div>
                        <div class="stat-label">Projects Completed</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?= htmlspecialchars($stats['achievements_earned'] ?? 0) ?></div>
                        <div class="stat-label">Achievements Earned</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?= htmlspecialchars($stats['badges_earned'] ?? 0) ?></div>
                        <div class="stat-label">Badges Earned</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?= htmlspecialchars($stats['items_donated'] ?? 0) ?></div>
                        <div class="stat-label">Total Items Donated</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?= htmlspecialchars($stats['items_recycled'] ?? 0) ?></div>
                        <div class="stat-label">Total Items Recycled</div>
                    </div>
                </div>
                <div class="tasks-section">
                    <div class="tasks-header">
                        <h3>My Tasks</h3>
                    </div>
                    <p class="tasks-subtitle">Complete all tasks to earn more badges and points!</p>
                    <div class="task-list">
                        <?php foreach ($tasks as $task): 
                            $is_completed = $task['status'] === 'Completed';
                            $reward_claimed = isset($task['reward_claimed']) ? $task['reward_claimed'] : 0;
                        ?>
                        <div class="task-item <?= $is_completed ? 'completed' : 'in-progress' ?>">
                            <div class="task-main">
                                <div class="task-info">
                                    <h4><?= htmlspecialchars($task['title']) ?></h4>
                                    <p><?= htmlspecialchars($task['description']) ?></p>
                                </div>
                                <div class="task-status">
                                    <span class="status-badge"><?= $is_completed ? 'Completed' : 'In Progress' ?></span>
                                </div>
                            </div>
                            <div class="task-rewards">
                                <div class="reward-amount">
                                    <i class="fas fa-award reward-icon"></i>
                                    <span><?= htmlspecialchars($task['reward']) ?></span>
                                </div>
                                <div class="task-progress">
                                    <?= htmlspecialchars($task['progress']) ?>
                                </div>
                            </div>
                            <?php if (!$is_completed): ?>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= 
                                    ($task['current_value'] / $task['target_value']) * 100 
                                ?>%"></div>
                            </div>
                            <?php elseif ($is_completed && !$reward_claimed): ?>
                            <form method="POST" class="redeem-form">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <button type="submit" name="redeem_reward" class="redeem-btn">Redeem Reward</button>
                            </form>
                            <?php elseif ($is_completed && $reward_claimed): ?>
                            <div class="reward-claimed">
                                <i class="fas fa-check-circle"></i> Reward Claimed
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
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
        document.getElementById('userProfile').addEventListener('click', function() {
            this.classList.toggle('active');
        });
        document.addEventListener('click', function(event) {
            const userProfile = document.getElementById('userProfile');
            if (!userProfile.contains(event.target)) {
                userProfile.classList.remove('active');
            }
        });
        
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