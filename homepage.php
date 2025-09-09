<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$conn = getDBConnection();
$donor_id = $_SESSION['user_id'];
$image_paths_json = null; // Default to null if no images are uploaded

// Handle form submission (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if required fields are set
    if (empty($_POST['wasteType']) || empty($_POST['quantity']) || empty($_POST['description'])) {
        die('Error: All fields are required.');
    }
    
    // Sanitize and assign form data
    $item_name = htmlspecialchars($_POST['wasteType']);
    $quantity = (int) $_POST['quantity'];
    $category = htmlspecialchars($_POST['wasteType']);
    $description = htmlspecialchars($_POST['description']);
    $donor_id = $_SESSION['user_id'];
    $donated_at = date('Y-m-d H:i:s');
    $image_paths_json = null;

    // Handle photo upload
    $image_paths = array();
    if (isset($_FILES['photos']) && count($_FILES['photos']['name']) > 0) {
        $upload_dir = 'assets/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        foreach ($_FILES['photos']['name'] as $key => $file_name) {
            if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['photos']['tmp_name'][$key];
                $file_type = mime_content_type($file_tmp);
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];

                if (!in_array($file_type, $allowed_types)) {
                    die('Error: Only JPG, PNG, and GIF files are allowed.');
                }

                $unique_file_name = uniqid() . '_' . basename($file_name);
                $target_file = $upload_dir . $unique_file_name;

                if (move_uploaded_file($file_tmp, $target_file)) {
                    $image_paths[] = $target_file; // Save the file path
                } else {
                    die('Failed to upload image: ' . $file_name);
                }
            }
        }
        
        // Convert image paths array to JSON for storage
        if (!empty($image_paths)) {
            $image_paths_json = json_encode($image_paths);
        }
    }

    // Insert donation into the database
    $stmt = $conn->prepare("INSERT INTO donations (item_name, quantity, category, description, donor_id, donated_at, status, image_path) VALUES (?, ?, ?, ?, ?, ?, 'Available', ?)");
    if (!$stmt) {
        die('Error: Failed to prepare statement. ' . $conn->error);
    }

    if (!$stmt->bind_param("sisssss", $item_name, $quantity, $category, $description, $donor_id, $donated_at, $image_paths_json)) {
        die('Error: Failed to bind parameters. ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        die('Error: Failed to execute statement. ' . $stmt->error);
    }

    // Refresh the page to show the new donation instead of redirecting
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Check login status
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Fetch user data
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
                    <?= strtoupper(substr(htmlspecialchars($user['first_name']), 0, 1)) ?>
                </div>
                <span class="profile-name"><?= htmlspecialchars($user['first_name']) ?></span>
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
                    <li><a href="homepage.php" style="color: rgb(4, 144, 4);"><i class="fas fa-home"></i>Home</a></li>
                    <li><a href="browse.php" ><i class="fas fa-search"></i>Browse</a></li>
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
                  <!-- Added role and tabindex for accessibility -->
<button type="button" class="btn" id="donateWasteBtn" role="button" tabindex="0">Donate Waste</button>
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
<div class="donation-post">
    <div class="donation-header">
        <h4><?= htmlspecialchars($donation['item_name']) ?></h4>
        <div class="donation-meta">
            <span class="category">Category: <?= htmlspecialchars($donation['category']) ?></span>
            <span class="quantity">Quantity: <?= htmlspecialchars($donation['quantity']) ?></span>
            <span class="time-ago"><?= htmlspecialchars(date('M d, Y', strtotime($donation['donated_at']))) ?></span>
        </div>
    </div>
                
    <div class="donation-description">
        <p><?= nl2br(htmlspecialchars($donation['description'] ?? '')) ?></p>
    </div>

<?php if (!empty($donation['image_path'])): ?>
    <?php
    $images = json_decode($donation['image_path'], true);
    if (is_array($images) && !empty($images)): ?>
        <div class="donation-images">
            <?php foreach ($images as $image): ?>
                <img src="<?= htmlspecialchars($image) ?>" alt="<?= htmlspecialchars($donation['item_name']) ?>" class="donation-image" onclick="openPhotoZoom('<?= htmlspecialchars($image) ?>')">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>


    
    <div class="donation-actions">
        <button class="request-btn">Request Donation</button>
                        <div class="donation-stats">
                            <a href="#" class="comments-toggle">0 Comments</a>
                        </div>
                    </div>
                   
                    <!-- Comments Section -->
                    <div class="comments-section" style="display: none;">
                        <div class="comments-list">
                            <!-- Comments will be loaded here -->
                            <p class="no-comments">No comments yet. Be the first to comment!</p>
                        </div>
                        <div class="add-comment">
                            <textarea id="addComment1" name="addComment1" placeholder="Add a comment..." class="comment-text"></textarea>
                            <button class="post-comment-btn">Post Comment</button>
                        </div>
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
            <?php if (!empty($idea['image_path'])): ?>
            <div class="idea-image-container">
                <img src="<?= htmlspecialchars($idea['image_path']) ?>" alt="<?= htmlspecialchars($idea['title']) ?>" class="idea-image">
            </div>
            <?php endif; ?>
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
        <form id="donationForm" action="homepage.php" method="POST" enctype="multipart/form-data">
            <!-- FIXED: Added missing div for form-group -->
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
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea id="description" name="description" placeholder="Describe your donation..." rows="4" required></textarea>
            </div>
            <div class="form-group">
                <label for="photos">Attach Photos (up to 5):</label>
                <div class="file-upload">
                    <input type="file" id="photos" name="photos[]" accept="image/*" multiple>
                    <label for="photos" class="file-upload-label">Choose Files</label>
                    <span id="file-chosen">No files chosen</span>
                </div>
                <small class="form-hint">You can upload up to 5 photos.</small>
                
                <!-- Scrollable image preview container -->
                <div id="photoPreviewContainer" class="photo-preview-container">
                    <div id="photoPreview" class="photo-preview"></div>
                </div>
            </div>
            <button type="submit" class="btn submit-btn">Post Donation</button>
        </form>
        <button class="close-btn">&times;</button>
    </div>
     <!-- Success Popup (inside the same container) -->
    <div class="popup-content success-popup" id="successPopup" style="display: none;">
        <h2>Congratulations!</h2>
        <p>You have<br>now donated your waste. Wait<br>for others to claim yours.</p>
        <button class="continue-btn" id="continueBtn">Continue</button>
        <button class="close-btn">&times;</button>
    </div>
</div>

<!-- Photo Zoom Modal -->
<div id="photoZoomModal" class="photo-zoom-modal" style="display:none;">
    <span class="close-modal">&times;</span>
    <img id="zoomedPhoto" class="zoomed-photo" src="" alt="Zoomed Photo">
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
    document.addEventListener('DOMContentLoaded', function () {
        const donateWasteBtn = document.getElementById('donateWasteBtn');
        const donationPopup = document.getElementById('donationPopup');
        const donationFormContainer = document.getElementById('donationFormContainer');
        const successPopup = document.getElementById('successPopup');

        if (!donateWasteBtn || !donationPopup) {
            console.error('Required elements not found in the DOM');
            return;
        }

        // Add event listener to the "Donate Waste" button
        donateWasteBtn.addEventListener('click', function (e) {
            e.preventDefault(); // Prevent default behavior
            console.log('Donate Waste button clicked');
            donationPopup.style.display = 'flex'; // Show the popup
            donationFormContainer.style.display = 'block'; // Ensure the form is visible
            successPopup.style.display = 'none'; // Hide the success popup
        });

        // Close the popup when clicking outside the form
        donationPopup.addEventListener('click', function (e) {
            if (e.target === donationPopup) {
                donationPopup.style.display = 'none'; // Hide the popup
            }
        });

        // Close the popup when clicking the close button
        document.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                donationPopup.style.display = 'none'; // Hide the popup
            });
        });

        // Form submission handling
        document.getElementById('donationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Basic form validation
            const wasteType = document.getElementById('wasteType').value;
            const quantity = document.getElementById('quantity').value;
            const description = document.getElementById('description').value;
            
            if (!wasteType || !quantity || !description) {
                alert('Please fill in all required fields');
                return;
            }
            
            // Submit the form programmatically
            this.submit();
        });

        // Continue button in success popup
        const continueBtn = document.getElementById('continueBtn');
        if (continueBtn) {
            continueBtn.addEventListener('click', function() {
                donationPopup.style.display = 'none';
                document.getElementById('donationForm').reset();
                document.getElementById('file-chosen').textContent = 'No files chosen';
                document.getElementById('photoPreview').innerHTML = '';
                selectedFiles = [];
                donationFormContainer.style.display = 'block';
                successPopup.style.display = 'none';
            });
        }

        // Fix for multiple image upload - store selected files
        let selectedFiles = [];
        const photoInput = document.getElementById('photos');
        
        photoInput.addEventListener('change', function() {
            const newFiles = Array.from(this.files);
            
            // Add new files to selectedFiles array
            newFiles.forEach(file => {
                // Check if file is already selected
                const isDuplicate = selectedFiles.some(
                    selectedFile => selectedFile.name === file.name && selectedFile.size === file.size
                );
                
                if (!isDuplicate && selectedFiles.length < 5) {
                    selectedFiles.push(file);
                }
            });
            
            // Update the file input with all selected files
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            photoInput.files = dataTransfer.files;
            
            // Update UI
            updateFileDisplay();
        });
        
        function updateFileDisplay() {
            const fileNames = selectedFiles.length > 0 
                ? selectedFiles.map(f => f.name).join(', ') 
                : 'No files chosen';
                
            document.getElementById('file-chosen').textContent = fileNames;
            
            // Preview images
            const photoPreview = document.getElementById('photoPreview');
            photoPreview.innerHTML = '';
            
            if (selectedFiles.length > 0) {
                // Show message about selected files
                const fileCount = document.createElement('p');
                fileCount.textContent = `Selected ${selectedFiles.length} file(s)`;
                fileCount.style.marginBottom = '10px';
                fileCount.style.fontSize = '14px';
                fileCount.style.color = '#666';
                fileCount.style.width = '100%';
                photoPreview.appendChild(fileCount);
                
                selectedFiles.forEach((file, index) => {
                    if (file.type.match('image.*')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const imageContainer = document.createElement('div');
                            imageContainer.className = 'photo-preview-item';
                            
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.alt = file.name;
                            img.title = file.name;
                            
                            // Add hover effect
                            img.addEventListener('mouseover', function() {
                                this.style.opacity = '0.8';
                            });
                            img.addEventListener('mouseout', function() {
                                this.style.opacity = '1';
                            });
                            
                            img.addEventListener('click', function () {
                                openPhotoZoom(e.target.result);
                            });
                            
                            // Add remove button
                            const removeBtn = document.createElement('button');
                            removeBtn.className = 'remove-image-btn';
                            removeBtn.innerHTML = '&times;';
                            removeBtn.title = 'Remove image';
                            
                            removeBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                // Remove file from selectedFiles
                                selectedFiles.splice(index, 1);
                                
                                // Update the file input
                                const dataTransfer = new DataTransfer();
                                selectedFiles.forEach(file => dataTransfer.items.add(file));
                                photoInput.files = dataTransfer.files;
                                
                                // Update UI
                                updateFileDisplay();
                            });
                            
                            imageContainer.appendChild(img);
                            imageContainer.appendChild(removeBtn);
                            photoPreview.appendChild(imageContainer);
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
            
            // Show warning if more than 5 files selected
            if (selectedFiles.length > 5) {
                const warning = document.createElement('p');
                warning.textContent = 'Maximum 5 files allowed. Only the first 5 will be uploaded.';
                warning.style.color = 'red';
                warning.style.fontSize = '12px';
                warning.style.marginTop = '10px';
                warning.style.width = '100%';
                photoPreview.appendChild(warning);
                
                // Keep only first 5 files
                selectedFiles = selectedFiles.slice(0, 5);
                
                // Update the file input
                const dataTransfer = new DataTransfer();
                selectedFiles.forEach(file => dataTransfer.items.add(file));
                photoInput.files = dataTransfer.files;
                
                // Update UI again
                updateFileDisplay();
            }
        }
    });

    // Comment functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle comments visibility
        document.querySelectorAll('.comments-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const commentsSection = this.closest('.donation-post').querySelector('.comments-section');
                const isVisible = commentsSection.style.display === 'block';
                
                commentsSection.style.display = isVisible ? 'none' : 'block';
                
                if (!isVisible) {
                    // Load comments if not already loaded
                    loadComments(this.closest('.donation-post'));
                }
            });
        });
        
        // Post comment functionality
        document.querySelectorAll('.post-comment-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); // Prevent the default form submission behavior
                const commentText = this.closest('.add-comment').querySelector('.comment-text');
                const comment = commentText.value.trim();

                if (comment) {
                    postComment(this.closest('.donation-post'), comment);
                    commentText.value = ''; // Clear the comment input field
                }
            });
        });
        
        // Allow pressing Enter to post comment (while holding Shift for new line)
        document.querySelectorAll('.comment-text').forEach(textarea => {
            textarea.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.closest('.add-comment').querySelector('.post-comment-btn').click();
                }
            });
        });
    });

    function loadComments(donationPost) {
        const commentsList = donationPost.querySelector('.comments-list');
        const noComments = donationPost.querySelector('.no-comments');
        
        // Simulate loading comments (replace with actual API call)
        setTimeout(() => {
            // In a real application, you would fetch comments from your server
            // For now, we'll just show the "no comments" message
            noComments.style.display = 'block';
        }, 500);
    }

    function postComment(donationPost, commentText) {
        const commentsList = donationPost.querySelector('.comments-list');
        const noComments = donationPost.querySelector('.no-comments');
        const commentsToggle = donationPost.querySelector('.comments-toggle');

        // Hide "no comments" message
        noComments.style.display = 'none';

        // Create new comment element
        const commentDiv = document.createElement('div');
        commentDiv.className = 'comment';
        commentDiv.innerHTML = `
            <div class="comment-author">You</div>
            <p class="comment-text">${commentText}</p>
        `;

        // Add comment to list
        commentsList.appendChild(commentDiv);

        // Update comments count
        const currentCount = parseInt(commentsToggle.textContent) || 0;
        commentsToggle.textContent = `${currentCount + 1} Comments`;

        // Scroll to the new comment
        commentDiv.scrollIntoView({ behavior: 'smooth' });
    }

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

    function openPhotoZoom(photoSrc) {
        const modal = document.getElementById('photoZoomModal');
        const zoomedPhoto = document.getElementById('zoomedPhoto');
        zoomedPhoto.src = photoSrc;
        modal.style.display = 'flex';
    }

    document.querySelector('.close-modal').addEventListener('click', function () {
        document.getElementById('photoZoomModal').style.display = 'none';
    });
</script>
</body>
</html>