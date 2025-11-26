<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// === Fetch leaderboard with profile info ===
$users = [];
$query = "
    SELECT 
        user_id, 
        first_name, 
        points 
    FROM users 
    ORDER BY points DESC 
    LIMIT 10
";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaderboard | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/leaderboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                <li><a href="leaderboard.php" class="active"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                <li><a href="donations.php"><i class="fas fa-hand-holding-heart"></i>Donations</a></li>
            </ul>
        </nav>
    </aside>
    <main class="main-content">
        <div class="leaderboard-container">
            <h2 class="leaderboard-title">Community Leaderboard</h2>
            <p class="leaderboard-subtitle">Top contributors making a difference for our planet</p>
            
            <table class="leaderboard-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>User</th>
                        <th>Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $i => $user): 
                        $rank = $i + 1;
                        $userId = isset($user['user_id']) ? (int)$user['user_id'] : 0;
                        $userName = !empty($user['first_name']) ? htmlspecialchars($user['first_name']) : 'Unknown User';
                        $profilePic = !empty($user['profile_pic']) ? htmlspecialchars($user['profile_pic']) : null;
                        $points = htmlspecialchars($user['points']);
                        
                        // Determine special styling for top ranks
                        $rankClass = '';
                        if ($rank === 1) {
                            $rankClass = 'rank-first';
                        } elseif ($rank === 2) {
                            $rankClass = 'rank-second';
                        } elseif ($rank === 3) {
                            $rankClass = 'rank-third';
                        }
                    ?>
                    <tr class="<?= $rankClass ?>">
                        <td class="rank">
                            <div class="rank-container">
                                <?php if ($rank <= 3): ?>
                                    <div class="rank-badge rank-<?= $rank ?>">
                                        <i class="fas fa-trophy"></i>
                                        <span><?= $rank ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="rank-number"><?= $rank ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="user-info">
                            <div class="user-link-wrapper">
                                <a href="profile.php?id=<?= $userId ?>" class="user-link">
                                    <div class="profile-pic">
                                        <?= strtoupper(substr($userName, 0, 1)) ?>
                                    </div>
                                    <span class="user-name"><?= $userName ?></span>
                                </a>
                            </div>
                        </td>
                        <td class="points">
                            <div class="points-container">
                                <span class="points-value"><?= $points ?></span>
                                <span class="points-label">pts</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (empty($users)): ?>
                <div class="empty-leaderboard">
                    <i class="fas fa-trophy"></i>
                    <h3>No Users Yet</h3>
                    <p>Be the first to earn points and appear on the leaderboard!</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Feedback Button -->
    <div class="feedback-btn" id="feedbackBtn">💬</div>
    <!-- Feedback Modal -->
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
    
    document.addEventListener("DOMContentLoaded", function () {
    // Grab elements
    const feedbackBtn = document.getElementById("feedbackBtn");
    const feedbackModal = document.getElementById("feedbackModal");
    const feedbackCloseBtn = document.getElementById("feedbackCloseBtn");
    const emojiOptions = feedbackModal ? feedbackModal.querySelectorAll(".emoji-option") : [];
    const feedbackSubmitBtn = document.getElementById("feedbackSubmitBtn");
    const feedbackText = document.getElementById("feedbackText");
    const ratingError = document.getElementById("ratingError");
    const textError = document.getElementById("textError");
    const thankYouMessage = document.getElementById("thankYouMessage");
    const feedbackForm = document.getElementById("feedbackForm");
    const spinner = document.getElementById("spinner");

    if (!feedbackBtn || !feedbackModal || !feedbackSubmitBtn || !feedbackText) return;

    let selectedRating = 0;

    // Open modal
    feedbackBtn.addEventListener("click", () => {
        feedbackModal.style.display = "flex";
        feedbackForm.style.display = "block";
        thankYouMessage.style.display = "none";
    });

    // Close modal
    feedbackCloseBtn?.addEventListener("click", () => feedbackModal.style.display = "none");
    window.addEventListener("click", e => {
        if (e.target === feedbackModal) feedbackModal.style.display = "none";
    });

    // Emoji rating selection
    emojiOptions.forEach(option => {
        option.addEventListener("click", () => {
            emojiOptions.forEach(o => o.classList.remove("selected"));
            option.classList.add("selected");
            selectedRating = option.getAttribute("data-rating");
            ratingError.style.display = "none";
        });
    });

    // Submit feedback
    feedbackSubmitBtn.addEventListener("click", e => {
        e.preventDefault();

        let valid = true;
        if (selectedRating === 0) { ratingError.style.display = "block"; valid = false; }
        if (feedbackText.value.trim() === "") { textError.style.display = "block"; valid = false; }
        else { textError.style.display = "none"; }

        if (!valid) return;

        spinner.style.display = "inline-block";
        feedbackSubmitBtn.disabled = true;

        // AJAX POST
        fetch("feedback_process.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `rating=${selectedRating}&feedback=${encodeURIComponent(feedbackText.value)}`
        })
        .then(res => res.json())
        .then(data => {
            spinner.style.display = "none";
            feedbackSubmitBtn.disabled = false;

            if (data.status === "success") {
                feedbackForm.style.display = "none";
                thankYouMessage.style.display = "block";

                // Reset after 3 seconds
                setTimeout(() => {
                    feedbackModal.style.display = "none";
                    feedbackForm.style.display = "block";
                    thankYouMessage.style.display = "none";
                    feedbackText.value = "";
                    selectedRating = 0;
                    emojiOptions.forEach(o => o.classList.remove("selected"));
                }, 3000);
            } else {
                alert(data.message || "Failed to submit feedback.");
            }
        })
        .catch(err => {
            spinner.style.display = "none";
            feedbackSubmitBtn.disabled = false;
            alert("Failed to submit feedback. Please try again.");
            console.error(err);
        });
    });
});
</script>
</body>
</html>