<?php
session_start();
require_once 'config.php';

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// Fetch My Donations
$myDonations = [];
$stmt = $conn->prepare("SELECT * FROM donations WHERE donor_id = ?");
$stmt->execute([$user_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $myDonations[] = $row;

// Fetch Requested Donations
$requestedDonations = [];
$stmt = $conn->prepare("SELECT * FROM donations WHERE requested_by = ?");
$stmt->execute([$user_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $requestedDonations[] = $row;

// Fetch Received Donations
$receivedDonations = [];
$stmt = $conn->prepare("SELECT * FROM donations WHERE receiver_id = ?");
$stmt->execute([$user_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $receivedDonations[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donations | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/donations.css">
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
                <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                <li><a href="donations.php" style="color: #2e8b57;"><i class="fas fa-box"></i>Donations</a></li>
            </ul>
        </nav>
    </aside>
    <main class="main-content">
        <div class="page-header">
            <h2 class="page-title">Donation Management</h2>
        </div>
        <p class="page-description">Track your donations, requests, and received items</p>
        <div class="tabs-container">
            <div class="tabs">
                <button class="tab-btn tab-btn-active" onclick="showTab('my-donations', this)">My Donations</button>
                <button class="tab-btn" onclick="showTab('requested-donations', this)">Requested Donations</button>
                <button class="tab-btn" onclick="showTab('received-donations', this)">Received Donations</button>
            </div>
        </div>
        <!-- My Donations Tab -->
        <div id="my-donations" class="tab-content tab-active">
            <?php foreach ($myDonations as $donation): ?>
            <div class="donation-card">
                <div class="donation-header">
                    <div class="donation-title"><?= htmlspecialchars($donation['item_name']) ?> (<?= htmlspecialchars($donation['quantity']) ?> items)</div>
                    <div class="donation-status"><?= htmlspecialchars($donation['status']) ?></div>
                </div>
                <div class="donation-details">
                    <div class="donation-detail"><strong>Donated:</strong> <?= htmlspecialchars($donation['donated_at']) ?></div>
                    <div class="donation-detail"><strong>Claimed by:</strong> <?= htmlspecialchars($donation['claimed_by_name'] ?? '') ?></div>
                    <div class="donation-detail"><strong>Project:</strong> <?= htmlspecialchars($donation['project_name'] ?? '') ?></div>
                    <div class="donation-detail"><strong>Delivered:</strong> <?= htmlspecialchars($donation['delivered_at'] ?? '') ?></div>
                </div>
                <a href="#" class="view-details">View Details</a>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Requested Donations Tab -->
        <div id="requested-donations" class="tab-content">
            <?php foreach ($requestedDonations as $donation): ?>
            <div class="donation-card">
                <div class="donation-header">
                    <div class="donation-title"><?= htmlspecialchars($donation['item_name']) ?> (<?= htmlspecialchars($donation['quantity']) ?> items)</div>
                    <div class="donation-status"><?= htmlspecialchars($donation['status']) ?></div>
                </div>
                <div class="donation-details">
                    <div class="donation-detail"><strong>Requested:</strong> <?= htmlspecialchars($donation['requested_at']) ?></div>
                    <div class="donation-detail"><strong>Project:</strong> <?= htmlspecialchars($donation['project_name'] ?? '') ?></div>
                    <div class="donation-detail"><strong>Requested by:</strong> <?= htmlspecialchars($_SESSION['first_name']) ?></div>
                    <div class="donation-detail"><strong>Status:</strong> <?= htmlspecialchars($donation['status']) ?></div>
                </div>
                <a href="#" class="view-details">View Request</a>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Received Donations Tab -->
        <div id="received-donations" class="tab-content">
            <?php foreach ($receivedDonations as $donation): ?>
            <div class="donation-card">
                <div class="donation-header">
                    <div class="donation-title"><?= htmlspecialchars($donation['item_name']) ?> (<?= htmlspecialchars($donation['quantity']) ?> items)</div>
                    <div class="donation-status"><?= htmlspecialchars($donation['status']) ?></div>
                </div>
                <div class="donation-details">
                    <div class="donation-detail"><strong>Received:</strong> <?= htmlspecialchars($donation['received_at']) ?></div>
                    <div class="donation-detail"><strong>Donated by:</strong> <?= htmlspecialchars($donation['donor_name'] ?? '') ?></div>
                    <div class="donation-detail"><strong>Project:</strong> <?= htmlspecialchars($donation['project_name'] ?? '') ?></div>
                    <div class="donation-detail"><strong>Handled by:</strong> <?= htmlspecialchars($_SESSION['first_name']) ?></div>
                </div>
                <a href="#" class="view-details">View Details</a>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
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
    function showTab(tabId, clickedBtn) {
        const contents = document.querySelectorAll('.tab-content');
        const buttons = document.querySelectorAll('.tab-btn');
        contents.forEach(c => c.classList.remove('tab-active'));
        buttons.forEach(b => b.classList.remove('tab-btn-active'));
        document.getElementById(tabId).classList.add('tab-active');
        clickedBtn.classList.add('tab-btn-active');
    }
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