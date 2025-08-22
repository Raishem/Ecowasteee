<?php
session_start();
require_once 'config.php';

// Check login status
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get user data from database
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Fetch available donations
$donations = [];
$result = $conn->query("SELECT * FROM donations WHERE status='Available' ORDER BY donated_at DESC LIMIT 5");
while ($row = $result->fetch_assoc()) $donations[] = $row;

// Fetch recycled ideas
$ideas = [];
$result = $conn->query("SELECT * FROM recycled_ideas ORDER BY posted_at DESC LIMIT 5");
while ($row = $result->fetch_assoc()) $ideas[] = $row;

// Fetch user stats
$stats = $conn->query("SELECT * FROM user_stats WHERE user_id = {$user['user_id']}")->fetch_assoc();

// Fetch user projects
$projects = [];
$result = $conn->query("SELECT * FROM projects WHERE user_id = {$user['user_id']} ORDER BY created_at DESC LIMIT 3");
while ($row = $result->fetch_assoc()) $projects[] = $row;

// Fetch leaderboard
$leaders = [];
$result = $conn->query("SELECT first_name, points FROM users ORDER BY points DESC LIMIT 10");
while ($row = $result->fetch_assoc()) $leaders[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/homepage.css">
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
                    <img src="assets/img/user-avatar.jpg" alt="User Profile">
                </div>
                <span class="profile-name"><?= htmlspecialchars($user['first_name']) ?></span>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                <div class="profile-dropdown">
                    <a href="#" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
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
                    <li><a href="homepage.php" style="color: rgb(4, 144, 4);"><i class="fas fa-home"></i>Home</a></li>
                    <li><a href="browse.php"><i class="fas fa-search"></i>Browse</a></li>
                    <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>
        <main class="main-content">
            <!-- Welcome Card -->
            <div class="welcome-card">
                <h2>WELCOME TO ECOWASTE</h2>
                <div class="divider"></div>
                <p>Join our community in making the world a cleaner place</p>
                <div class="btn-container">
                    <a href="#" class="btn" id="donateWasteBtn">Donate Waste</a>
                    <a href="start_project.php" class="btn">Start Recycling</a>
                    <a href="learn_more.php" class="btn" style="background-color: #666;">Learn More</a>
                </div>
            </div>
            <!-- Tab Navigation -->
            <div class="tab-container">
                <button class="tab-btn active" onclick="openTab('donations')">Donations</button>
                <button class="tab-btn" onclick="openTab('recycled-ideas')">Recycled Ideas</button>
            </div>
            <div class="divider"></div>
            <!-- Donations Tab Content -->
            <div id="donations" class="tab-content" style="display: block;">
                <div class="section-card">
                    <h3>Available</h3>
                    <div id="availableDonationsContainer">
                        <?php if (count($donations) === 0): ?>
                            <p>No donations available.</p>
                        <?php else: ?>
                            <?php foreach ($donations as $donation): ?>
                            <div class="donation-item">
                                <div class="donation-info">
                                    <h4><?= htmlspecialchars($donation['item_name']) ?></h4>
                                    <p>Category: <?= htmlspecialchars($donation['category']) ?></p>
                                    <p class="time-ago"><?= htmlspecialchars(date('M d, Y', strtotime($donation['donated_at']))) ?></p>
                                </div>
                                <div class="donation-action">
                                    <div class="quantity">Quantity: <?= htmlspecialchars($donation['quantity']) ?></div>
                                    <button class="action-btn">Request Donation</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Recycled Ideas Tab Content -->
            <div id="recycled-ideas" class="tab-content" style="display:none;">
                <?php if (count($ideas) === 0): ?>
                    <p>No recycled ideas yet.</p>
                <?php else: ?>
                    <?php foreach ($ideas as $idea): ?>
                    <div class="idea-card">
                        <div class="idea-header">
                            <h3><?= htmlspecialchars($idea['title']) ?></h3>
                            <div class="idea-meta">
                                <span class="author"><?= htmlspecialchars($idea['author']) ?></span>
                                <span class="time-ago"><?= htmlspecialchars(date('M d, Y', strtotime($idea['posted_at']))) ?></span>
                            </div>
                        </div>
                        <p class="idea-description"><?= htmlspecialchars($idea['description']) ?></p>
                        <div class="idea-actions">
                            <button class="action-btn">Try This Idea</button>
                            <span class="comments">0 Comments</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
        <div class="right-sidebar">
            <div class="card">
                <h2>Your Projects</h2>
                <div class="divider"></div>
                <?php if (count($projects) === 0): ?>
                    <p>No Projects for now</p>
                <?php else: ?>
                    <?php foreach ($projects as $project): ?>
                        <div class="project-item">
                            <strong><?= htmlspecialchars($project['project_name']) ?></strong>
                            <div><?= htmlspecialchars($project['description']) ?></div>
                            <div class="project-date"><?= htmlspecialchars(date('M d, Y', strtotime($project['created_at']))) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="card">
                <h2>Quick Stats</h2>
                <div class="divider"></div>
                <div class="stat-item">
                    <div class="stat-label">Items Recycled</div>
                    <div class="stat-value"><?= htmlspecialchars($stats['items_recycled'] ?? 0) ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Items Donated</div>
                    <div class="stat-value"><?= htmlspecialchars($stats['items_donated'] ?? 0) ?></div>
                </div>
            </div>
            <div class="card">
                <h3>Leaderboard</h3>
                <div class="divider"></div>
                <div class="leaderboard-header">
                    <span>TOP 10 USERS</span>
                </div>
                <?php foreach ($leaders as $i => $leader): ?>
                <div class="leaderboard-item">
                    <span class="rank"><?= $i + 1 ?></span>
                    <span class="name"><?= htmlspecialchars($leader['first_name']) ?></span>
                    <span class="points"><?= htmlspecialchars($leader['points']) ?> pts</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- Donation Popup Form -->
    <div id="donationPopup" class="popup-container" style="display:none;">
        <div class="popup-content" id="donationFormContainer">
            <h2>Post Donation</h2>
            <form id="donationForm" action="donate_process.php" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="wasteType">Type of Waste:</label>
                    <select id="wasteType" name="wasteType" required>
                        <option value="">Select waste type</option>
                        <option value="Cans">Cans</option>
                        <option value="Plastic Bottle">Plastic Bottle</option>
                        <option value="Plastic">Plastic</option>
                        <option value="Paper">Paper</option>
                        <option value="Metal">Metal</option>
                        <option value="Glass">Glass</option>
                        <option value="Organic">Organic</option>
                        <option value="Electronic">Electronic</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity:</label>
                    <input type="text" id="quantity" name="quantity" placeholder="Enter quantity" required>
                </div>
                <div class="divider"></div>
                <div class="form-group">
                    <label>Attach Photo:</label>
                    <div class="file-upload">
                        <input type="file" id="photo" name="photo" accept="image/*">
                        <label for="photo" class="file-upload-label">Choose File</label>
                        <span id="file-chosen">No file chosen</span>
                    </div>
                </div>
                <div class="divider"></div>
                <button type="submit" class="btn submit-btn">Post Donation</button>
            </form>
            <button class="close-btn">&times;</button>
        </div>
        <div class="popup-content success-popup" id="successPopup" style="display: none;">
            <h2>Congratulations!</h2>
            <p>You have<br>now donated your waste. Wait<br>for others to claim yours.</p>
            <button class="continue-btn" id="continueBtn">Continue</button>
            <button class="close-btn">&times;</button>
        </div>
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
        // Tab Functionality
        function openTab(tabName) {
            document.getElementById('donations').style.display = tabName === 'donations' ? 'block' : 'none';
            document.getElementById('recycled-ideas').style.display = tabName === 'recycled-ideas' ? 'block' : 'none';
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            if (tabName === 'donations') document.querySelectorAll('.tab-btn')[0].classList.add('active');
            else document.querySelectorAll('.tab-btn')[1].classList.add('active');
        }
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
        // Donation Popup Functionality
        document.getElementById('donateWasteBtn').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('donationPopup').style.display = 'flex';
            document.getElementById('donationFormContainer').style.display = 'block';
            document.getElementById('successPopup').style.display = 'none';
        });
        document.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('donationPopup').style.display = 'none';
            });
        });
        document.getElementById('donationPopup').addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
        document.getElementById('photo').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : 'No file chosen';
            document.getElementById('file-chosen').textContent = fileName;
        });
      //  document.getElementById('donationForm').addEventListener('submit', function(e) {
      //      e.preventDefault();
       //     document.getElementById('donationFormContainer').style.display = 'none';
       //     document.getElementById('successPopup').style.display = 'block';
    //    });
        document.getElementById('continueBtn').addEventListener('click', function() {
            document.getElementById('donationPopup').style.display = 'none';
            document.getElementById('donationForm').reset();
            document.getElementById('file-chosen').textContent = 'No file chosen';
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