<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Fetch stats
$stats = $conn->query("SELECT * FROM user_stats WHERE user_id = $user_id")->fetch_assoc();

// Fetch tasks
$tasks = [];
$result = $conn->query("SELECT * FROM user_tasks WHERE user_id = $user_id");
while ($row = $result->fetch_assoc()) $tasks[] = $row;
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
<body>
<div class="container">
    <header>
        <div class="logo-container">
            <div class="logo">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo">
            </div>
            <h1>EcoWaste</h1>
        </div>
        <div class="user-profile" id="userProfile">
            <div class="profile-pic">
                <img src="assets/img/user-avatar.jpg" alt="User Profile">
            </div>
            <span class="profile-name"><?= htmlspecialchars($_SESSION['first_name'] ?? 'User') ?></span>
            <i class="fas fa-chevron-down dropdown-arrow"></i>
            <div class="profile-dropdown">
                <a href="#" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
                <a href="#" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                <div class="dropdown-divider"></div>
                <a href="logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>
    <aside class="sidebar">
        <nav>
            <ul>
                <li><a href="homepage.php"><i class="fas fa-home"></i>Home</a></li>
                <li><a href="browse.php"><i class="fas fa-search"></i>Browse</a></li>
                <li><a href="achievements.php" style="color: rgb(4, 144, 4);"><i class="fas fa-star"></i>Achievements</a></li>
                <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                <li><a href="start_project.php"><i class="fas fa-recycle"></i>Projects</a></li>
                <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
            </ul>
        </nav>
    </aside>
    <main class="main-content">
        <div class="achievements-header">
            <h2>My Achievements</h2>
            <p class="subtitle">Track your eco-friendly progress and accomplishments</p>
        </div>
        <div class="achievements-content">
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
                <div class="task-list">
                    <?php foreach ($tasks as $task): ?>
                    <div class="task-item <?= htmlspecialchars($task['status']) ?>">
                        <div class="task-main">
                            <div class="task-info">
                                <h4><?= htmlspecialchars($task['title']) ?></h4>
                                <p><?= htmlspecialchars($task['description']) ?></p>
                            </div>
                            <div class="task-status">
                                <span class="status-badge"><?= htmlspecialchars($task['status']) ?></span>
                            </div>
                        </div>
                        <div class="task-rewards">
                            <div class="reward-amount">
                                <span><?= htmlspecialchars($task['reward']) ?></span>
                            </div>
                            <div class="task-progress"><?= htmlspecialchars($task['progress']) ?></div>
                        </div>
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