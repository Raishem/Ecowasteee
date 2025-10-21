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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learn More | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/learn_more.css">
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
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-hand-holding-heart"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <div class="page-header" style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
                <button class="back-btn" onclick="window.history.back()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <h2 class="page-title" style="margin: 0;">Learn More About EcoWaste</h2>
            </div>

            <!-- Tabs Navigation -->
            <div class="tabs-container">
                <div class="tabs">
                    <button class="tab-btn active" data-tab="recycling">Recycling Benefits</button>
                    <button class="tab-btn" data-tab="impacts">Waste Impacts</button>
                    <button class="tab-btn" data-tab="sustainability">Sustainable Living</button>
                    <button class="tab-btn" data-tab="ecowaste">About EcoWaste</button>
                    <div class="tab-underline"></div>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="tab-content-container">

                <!-- Recycling Benefits -->
                <div class="tab-content active" id="recycling">
                    <h3>Recycling Benefits</h3>
                    <p>Recycling conserves resources, saves energy, and reduces landfill waste. By reusing materials, we minimize pollution and protect the planet for future generations.</p>
                    <ul>
                        <li>Reduces the need for new raw materials</li>
                        <li>Prevents pollution by reducing the need to collect new materials</li>
                        <li>Saves energy and water resources</li>
                        <li>Supports green jobs and sustainable industries</li>
                    </ul>
                    <section class="education-section">
                        <h3>Watch and Learn: Environmental Awareness Videos</h3>
                            <div class="video-grid">
                                <iframe src="https://www.youtube.com/embed/6jQ7y_qQYUA" title="How Recycling Works | SciShow" allowfullscreen></iframe>
                                <iframe width="560" height="315" src="https://www.youtube.com/embed/xpAnLXc_bIU?si=HllPtiZ4CdydN5JS" title="YouTube video player" frameborder="0"  allowfullscreen></iframe>
                            </div>
                    </section>        
                </div>

                <!-- Waste Impacts -->
                <div class="tab-content" id="impacts">
                    <h3>Waste Impacts</h3>
                    <p>Improper waste management leads to pollution, health issues, and ecosystem damage. Every piece of trash that ends up in nature has long-lasting effects.</p>
                    <ul>
                        <li>Plastic waste harms marine life and contaminates food chains</li>
                        <li>Improper disposal leads to toxic soil and water contamination</li>
                        <li>Landfills release methane — a major greenhouse gas</li>
                        <li>Air pollution from burning waste contributes to global warming</li>
                    </ul>
                    <section class="education-section">
                        <h3>Watch and Learn: Environmental Awareness Videos</h3>
                            <div class="video-grid">
                                <iframe src="https://www.youtube.com/embed/9GorqroigqM" title="The Life of Plastic | National Geographic" allowfullscreen></iframe>
                                
                            </div>
                    </section>
                </div>

                <!-- Sustainable Living -->
                <div class="tab-content" id="sustainability">
                    <h3>Sustainable Living</h3>
                    <p>Sustainability starts with daily habits — small changes create long-term impact. Live consciously by conserving resources and supporting green initiatives.</p>
                    <ul>
                        <li>Use reusable bags, bottles, and containers</li>
                        <li>Switch to energy-efficient appliances</li>
                        <li>Compost organic waste</li>
                        <li>Support local eco-friendly products and businesses</li>
                    </ul>
                    <section class="education-section">
                        <h3>Watch and Learn: Environmental Awareness Videos</h3>
                        <div class="video-grid">
                            <iframe  src="https://www.youtube.com/embed/4JDGFNoY-rQ?si=QFUK-yiPmYKCgkF1" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                            <iframe  src="https://www.youtube.com/embed/0ZiD_Lb3Tm0?si=pUAXBW1jg23ZqhH8" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                        </div>
                    </section>
                </div>

                <!-- About EcoWaste -->
                <div class="tab-content" id="ecowaste">
                    <div class="info-section">
                        <h3>About EcoWaste</h3>
                        <p>EcoWaste empowers communities to manage waste sustainably by combining education, digital tracking, and donation programs.</p>

                        <h3> Our Mission </h3>
                        <p>To create a sustainable future by reducing waste, promoting recycling, and empowering communities to take action for the environment.</p>

                        <h3>How it works</h3>
                            <ol>
                                <li>Start recycling projects to track your environmental impact</li>
                                <li>Donate items you no longer need instead of throwing them away</li>
                                <li>Browse available donations from other community members</li>
                                <li>Earn achievements and climb the leaderboard as you contribute</li>
                            </ol>
                        
                        <h3>Benefits of Using EcoWaste</h3>
                        <ul>
                            <li>Reduce your environmental footprint</li>
                            <li>Connect with like-minded individuals</li>
                            <li>Track your recycling progress and impact</li>
                            <li>Find new homes for items you no longer need</li>
                            <li>Earn recognition for your environmental efforts</li>
                        </ul>

                        <h3>Aligns with SDGs:</h3>
                        <ul>
                            <li>SDG 13: Climate Action - By promoting recycling and waste reduction, EcoWaste helps mitigate climate change impacts.</li>
                            <li>SDG 14: Life Below Water - Reducing plastic waste through recycling efforts helps protect marine ecosystems.</li>
                        
                        <div class="action-buttons">
                                <a href="start_project.php" class="action-btn">Start Your First Project</a>
                                <a href="browse.php" class="action-btn">Browse Donations</a>
                        </div>
                    </div>
                </div>
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
        // User profile dropdown
        document.getElementById('userProfile').addEventListener('click', function() {
            this.classList.toggle('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const userProfile = document.getElementById('userProfile');
            if (!userProfile.contains(event.target)) {
                userProfile.classList.remove('active');
            }
        });


        const tabButtons = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');
        const underline = document.querySelector('.tab-underline');

        function moveUnderline(activeBtn) {
            const rect = activeBtn.getBoundingClientRect();
            const parentRect = activeBtn.parentElement.getBoundingClientRect();
            underline.style.width = `${rect.width}px`;
            underline.style.left = `${rect.left - parentRect.left}px`;
        }

        tabButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                tabButtons.forEach(b => b.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.tab).classList.add('active');
                moveUnderline(btn);
            });
        });

        // Initialize underline on load
        window.addEventListener('load', () => {
            const activeBtn = document.querySelector('.tab-btn.active');
            moveUnderline(activeBtn);
        });


        // Feedback system
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
            
            // Emoji selection
            emojiOptions.forEach(option => {
                option.addEventListener('click', () => {
                    emojiOptions.forEach(opt => opt.classList.remove('selected'));
                    option.classList.add('selected');
                    selectedRating = option.getAttribute('data-rating');
                    ratingError.style.display = 'none';
                });
            });
            
            // Form submission
            feedbackForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Validate form
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
                
                // Show loading spinner
                feedbackSubmitBtn.disabled = true;
                spinner.style.display = 'block';
                
                // Simulate API call
                setTimeout(() => {
                    // Hide spinner
                    spinner.style.display = 'none';
                    
                    // Show thank you message
                    feedbackForm.style.display = 'none';
                    thankYouMessage.style.display = 'block';
                    
                    // Reset form after delay
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
            
            // Open/close feedback modal
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