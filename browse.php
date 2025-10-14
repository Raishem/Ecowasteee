<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get user data from database
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Fetch donations
$donations = [];
$result = $conn->query("SELECT * FROM donations WHERE status='Available' ORDER BY donated_at DESC");
while ($row = $result->fetch_assoc()) $donations[] = $row;

// Fetch recycled ideas
$ideas = [];
$result = $conn->query("SELECT * FROM recycled_ideas ORDER BY posted_at DESC");
while ($row = $result->fetch_assoc()) $ideas[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse | EcoWaste</title>
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
        
        /* Browse-specific styles */
        .search-bar {
            margin-bottom: 20px;
        }
        
        .search-bar input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            background-color: white;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%23999" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>');
            background-repeat: no-repeat;
            background-position: 15px center;
            background-size: 16px;
            padding-left: 40px;
        }
        
        .categories {
            margin-bottom: 20px;
        }
        
        .category-scroll-container {
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 10px;
            -webkit-overflow-scrolling: touch;
        }
        
        .category-list {
            display: inline-flex;
            list-style: none;
            gap: 10px;
        }
        
        .category-list li {
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            background-color: #f5f5f5;
            transition: all 0.2s;
            flex-shrink: 0;
            font-size: 14px;
        }
        
        .category-list li.active {
            background-color: #2e8b57;
            color: white;
            font-weight: 500;
        }
        
        /* Donation items styling to match homepage */
        .available-item,
        .idea-item {
            background-color: white;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .item-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            align-items: flex-start;
            flex-direction: column;
            gap: 8px;
        }
        
        .item-title {
            font-weight: 600;
            font-size: 18px;
            color: #2e8b57;
        }
        
        .item-category {
            color: #2e8b57;
            font-size: 14px;
            background-color: #e8f5e9;
            padding: 4px 10px;
            border-radius: 4px;
        }
        
        .item-time {
            color: #999;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .item-quantity {
            font-size: 14px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .request-btn {
            padding: 10px 20px;
            background-color: #2e8b57;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .request-btn:hover {
            background-color: #3cb371;
        }
        
        /* Idea item styling */
        .idea-title {
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 10px;
            color: #2e8b57;
        }
        
        .idea-description {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .idea-author {
            color: #999;
            font-size: 13px;
            font-style: italic;
            margin-bottom: 15px;
        }
        
        /* Hide scrollbar but keep functionality */
        .category-scroll-container::-webkit-scrollbar {
            height: 5px;
        }
        
        .category-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        .category-scroll-container::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 10px;
        }
        
        .content-section {
            display: none;
        }
        
        .content-section.active {
            display: block;
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
                    <li><a href="browse.php" style="color: rgb(4, 144, 4);"><i class="fas fa-search"></i>Browse</a></li>
                    <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="shared_feed.php"><i class="fas fa-share-alt"></i>Shared Feed</a></li>
                    <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>
        
        <main class="main-content">
            <div class="search-bar">
                        <?php $from_project = isset($_GET['from_project']) ? (int)$_GET['from_project'] : 0; $query = isset($_GET['query']) ? htmlspecialchars($_GET['query']) : ''; ?>
                        <input type="text" placeholder="Search Donations..." value="<?= $query ?>">
                        <?php if ($from_project): ?>
                            <div class="back-to-project">
                                <a href="project_details.php?id=<?= $from_project ?>" class="btn">&larr; Back To Project</a>
                            </div>
                        <?php endif; ?>
            </div>
            
            <!-- Tab Navigation -->
            <div class="tab-container">
                <button class="tab-btn active" onclick="openTab('donations')">Donations</button>
                <button class="tab-btn" onclick="openTab('recycled-ideas')">Recycled Ideas</button>
            </div>
            <div class="divider"></div>
            
            <!-- Donations Tab Content -->
            <div id="donations" class="tab-content" style="display: block;">
                <div class="categories">
                    <div class="category-scroll-container">
                        <ul class="category-list">
                            <li class="active">All</li>
                            <li>Cans</li>
                            <li>Plastic</li>
                            <li>Plastic Bottle</li>
                            <li>Paper</li>
                            <li>Cardboard</li>
                            <li>Glass</li>
                            <li>Metal</li>
                            <li>Textiles</li>
                            <li>Electronics</li>
                        </ul>
                    </div>
                </div>
                
                <div class="section-card">
                    <h3>Available Donations</h3>
                    <div class="available">
                        <?php if (count($donations) === 0): ?>
                            <p>No donations available.</p>
                        <?php else: ?>
                            <?php foreach ($donations as $donation): ?>
                            <div class="available-item">
                                <div class="item-header">
                                    <div class="item-title"><?= htmlspecialchars($donation['item_name']) ?></div>
                                    <div class="item-category">Category: <?= htmlspecialchars($donation['category']) ?></div>
                                </div>
                                <div class="item-time"><?= htmlspecialchars(date('M d, Y', strtotime($donation['donated_at']))) ?></div>
                                <div class="item-quantity">Quantity: <?= htmlspecialchars($donation['quantity']) ?></div>
                                <button class="request-btn">Request Donation</button>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recycled Ideas Tab Content -->
            <div id="recycled-ideas" class="tab-content" style="display:none;">
                <div class="categories">
                    <div class="category-scroll-container">
                        <ul class="category-list">
                            <li class="active">All</li>
                            <li>Plastic</li>
                            <li>Paper</li>
                            <li>Glass</li>
                            <li>Metal</li>
                            <li>Textiles</li>
                            <li>Electronics</li>
                            <li>Compost</li>
                        </ul>
                    </div>
                </div>
                
                <div class="section-card">
                    <h3>Recycled Ideas</h3>
                    <div class="ideas-list">
                        <?php if (count($ideas) === 0): ?>
                            <p>No recycled ideas yet.</p>
                        <?php else: ?>
                            <?php foreach ($ideas as $idea): ?>
                            <div class="idea-item">
                                <div class="idea-title"><?= htmlspecialchars($idea['title']) ?></div>
                                <div class="idea-description"><?= htmlspecialchars($idea['description']) ?></div>
                                <div class="idea-author">Posted by <?= htmlspecialchars($idea['author']) ?></div>
                                <button class="request-btn">Try This Idea</button>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
        // Tab Functionality
        function openTab(tabName) {
            document.getElementById('donations').style.display = tabName === 'donations' ? 'block' : 'none';
            document.getElementById('recycled-ideas').style.display = tabName === 'recycled-ideas' ? 'block' : 'none';
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            
            const activeTab = document.querySelector(`.tab-btn[onclick="openTab('${tabName}')"]`);
            if (activeTab) {
                activeTab.classList.add('active');
            }
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
        
        // Category selection
        document.querySelectorAll('.category-list li').forEach(item => {
            item.addEventListener('click', function() {
                this.parentElement.querySelectorAll('li').forEach(li => {
                    li.classList.remove('active');
                });
                this.classList.add('active');
            });
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