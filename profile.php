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

// Handle form submission for profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = $_POST['first_name'] ?? '';
    $middle_name = $_POST['middle_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $address = $_POST['address'] ?? '';
    $city = $_POST['city'] ?? '';
    $zip_code = $_POST['zip_code'] ?? '';
    
    // Update user data in database
    $update_query = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, contact_number = ?, address = ?, city = ?, zip_code = ? WHERE user_id = ?");
    $update_query->bind_param("ssssssssi", $first_name, $middle_name, $last_name, $email, $contact_number, $address, $city, $zip_code, $user_id);
    
    if ($update_query->execute()) {
        $success_message = "Profile updated successfully!";
        // Refresh user data
        $_SESSION['success_message'] = $success_message;
        header("Location: profile.php");
        exit();
    } else {
        $error_message = "Error updating profile: " . $conn->error;
    }
}

// Create necessary tables if they don't exist
$create_tables_sql = [
    "CREATE TABLE IF NOT EXISTS user_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        activity_type ENUM('recycling', 'donation', 'badge', 'project') NOT NULL,
        description TEXT NOT NULL,
        points_earned INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB",
    
    "CREATE TABLE IF NOT EXISTS badges (
        badge_id INT AUTO_INCREMENT PRIMARY KEY,
        badge_name VARCHAR(100) NOT NULL,
        description TEXT NOT NULL,
        icon VARCHAR(50) DEFAULT 'fas fa-award',
        points_required INT DEFAULT 0
    ) ENGINE=InnoDB",
    
    "CREATE TABLE IF NOT EXISTS user_badges (
        user_id INT NOT NULL,
        badge_id INT NOT NULL,
        earned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, badge_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (badge_id) REFERENCES badges(badge_id) ON DELETE CASCADE
    ) ENGINE=InnoDB",
    
    "CREATE TABLE IF NOT EXISTS user_stats (
        user_id INT PRIMARY KEY,
        projects_completed INT DEFAULT 0,
        achievements_earned INT DEFAULT 0,
        badges_earned INT DEFAULT 0,
        items_donated INT DEFAULT 0,
        items_recycled INT DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
];

foreach ($create_tables_sql as $sql) {
    try {
        $conn->query($sql);
    } catch (Exception $e) {
        // Log error but continue execution
        error_log("Table creation error: " . $e->getMessage());
    }
}

// Insert sample badges if none exist
$check_badges = $conn->query("SELECT COUNT(*) as count FROM badges");
if ($check_badges->fetch_assoc()['count'] == 0) {
    $sample_badges = [
        "('Eco Beginner', 'Completed your first eco activity', 'fas fa-seedling', 10)",
        "('Recycling Pro', 'Recycled 10+ items', 'fas fa-recycle', 50)",
        "('Donation Hero', 'Donated 5+ items', 'fas fa-hand-holding-heart', 75)",
        "('Project Master', 'Completed 3+ projects', 'fas fa-project-diagram', 100)",
        "('Eco Warrior', 'Earned 200+ points', 'fas fa-trophy', 200)"
    ];
    
    $conn->query("INSERT INTO badges (badge_name, description, icon, points_required) VALUES " . implode(',', $sample_badges));
}

// Insert sample activities if none exist for this user
$check_activities = $conn->prepare("SELECT COUNT(*) as count FROM user_activities WHERE user_id = ?");
$check_activities->bind_param("i", $user_id);
$check_activities->execute();
$activity_count = $check_activities->get_result()->fetch_assoc()['count'];

if ($activity_count == 0) {
    $sample_activities = [
        "($user_id, 'recycling', 'Recycled plastic bottles', 15)",
        "($user_id, 'donation', 'Donated old clothes', 20)",
        "($user_id, 'badge', 'Earned Eco Beginner badge', 10)",
        "($user_id, 'project', 'Completed Community Cleanup project', 25)"
    ];
    
    $conn->query("INSERT INTO user_activities (user_id, activity_type, description, points_earned) VALUES " . implode(',', $sample_activities));
}

// Ensure user has stats record
$check_stats = $conn->prepare("SELECT COUNT(*) as count FROM user_stats WHERE user_id = ?");
$check_stats->bind_param("i", $user_id);
$check_stats->execute();
$stats_count = $check_stats->get_result()->fetch_assoc()['count'];

if ($stats_count == 0) {
    $conn->query("INSERT INTO user_stats (user_id, projects_completed, achievements_earned, badges_earned, items_donated, items_recycled) 
                  VALUES ($user_id, 1, 1, 1, 1, 1)");
}

// Fetch user data
$user_query = $conn->prepare("SELECT user_id, email, first_name, middle_name, last_name, contact_number, address, city, zip_code, created_at, points FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Fetch user stats
$stats_query = $conn->prepare("SELECT projects_completed, achievements_earned, badges_earned, items_donated, items_recycled FROM user_stats WHERE user_id = ?");
$stats_query->bind_param("i", $user_id);
$stats_query->execute();
$stats_result = $stats_query->get_result();
$stats_data = $stats_result->fetch_assoc();

// Calculate level based on points
$level = floor(($user_data['points'] ?? 0) / 25);

// Fetch user badges
$badges = [];
$badges_query = $conn->prepare("
    SELECT b.badge_name, b.description, b.icon 
    FROM user_badges ub 
    JOIN badges b ON ub.badge_id = b.badge_id 
    WHERE ub.user_id = ? 
    ORDER BY ub.earned_date DESC 
    LIMIT 5
");
$badges_query->bind_param("i", $user_id);
$badges_query->execute();
$badges_result = $badges_query->get_result();
while ($row = $badges_result->fetch_assoc()) {
    $badges[] = $row;
}

// If no badges but user has points, auto-assign appropriate badges
if (empty($badges) && isset($user_data['points'])) {
    $points = $user_data['points'];
    $auto_badges = $conn->prepare("SELECT badge_id FROM badges WHERE points_required <= ? ORDER BY points_required DESC");
    $auto_badges->bind_param("i", $points);
    $auto_badges->execute();
    $auto_badges_result = $auto_badges->get_result();
    
    while ($badge = $auto_badges_result->fetch_assoc()) {
        // Assign badge to user
        $assign_badge = $conn->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)");
        $assign_badge->bind_param("ii", $user_id, $badge['badge_id']);
        $assign_badge->execute();
        
        // Add to badges array for display
        $badge_info = $conn->prepare("SELECT badge_name, description, icon FROM badges WHERE badge_id = ?");
        $badge_info->bind_param("i", $badge['badge_id']);
        $badge_info->execute();
        $badge_info_result = $badge_info->get_result();
        if ($badge_data = $badge_info_result->fetch_assoc()) {
            $badges[] = $badge_data;
        }
    }
    
    // Update badges earned count
    if (!empty($badges)) {
        $conn->query("UPDATE user_stats SET badges_earned = " . count($badges) . " WHERE user_id = $user_id");
    }
}

// Fetch recent activities
$activities = [];
$activities_query = $conn->prepare("
    SELECT activity_type, description, points_earned, created_at 
    FROM user_activities 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 4
");
if ($activities_query) {
    $activities_query->bind_param("i", $user_id);
    $activities_query->execute();
    $activities_result = $activities_query->get_result();
    while ($row = $activities_result->fetch_assoc()) {
        $activities[] = $row;
    }
}

// Get activity icon based on type
function getActivityIcon($type) {
    switch ($type) {
        case 'recycling':
            return 'fas fa-recycle';
        case 'donation':
            return 'fas fa-hand-holding-heart';
        case 'badge':
            return 'fas fa-trophy';
        case 'project':
            return 'fas fa-project-diagram';
        default:
            return 'fas fa-star';
    }
}

// Format date
function formatDate($date) {
    $now = new DateTime();
    $date = new DateTime($date);
    $interval = $now->diff($date);
    
    if ($interval->y > 0) return $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
    if ($interval->m > 0) return $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
    if ($interval->d > 0) return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
    if ($interval->h > 0) return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
    if ($interval->i > 0) return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

// Get full name
function getFullName($first, $middle, $last) {
    $name = $first;
    if (!empty($middle)) $name .= ' ' . $middle;
    if (!empty($last)) $name .= ' ' . $last;
    return $name;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile | EcoWaste</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/profile.css">
    
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
                <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background-color: #3d6a06ff; color: white; font-weight: bold;">
                    <?= strtoupper(substr($user_data['first_name'], 0, 1)) ?>
                </div>
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
                    <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-hand-holding-heart"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>
        <main class="main-content">
            <!-- Back navigation and page title -->
            <div class="back-navigation">
                <a href="homepage.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <h2 class="page-title">Profile</h2>
            </div>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="success-message">
                    <?= $_SESSION['success_message'] ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <div class="profile-header">
                <div class="profile-avatar">
                    <?= strtoupper(substr($user_data['first_name'], 0, 1)) ?>
                </div>
                <div class="profile-info">
                    <h2><?= htmlspecialchars(getFullName($user_data['first_name'], $user_data['middle_name'], $user_data['last_name'])) ?></h2>
                    <p>Member since <?= date('F Y', strtotime($user_data['created_at'] ?? 'now')) ?></p>
                    <?php if (!empty($user_data['city'])): ?>
                    <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($user_data['city']) ?></p>
                    <?php endif; ?>
                    <span class="profile-level">Level <?= $level ?> Eco Warrior</span>
                </div>
                <button class="edit-profile-btn" id="editProfileBtn">
                    <i class="fas fa-edit"></i> Edit Profile
                </button>
            </div>

            <div class="user-details">
                <h3>Personal Information</h3>
                <div class="details-grid">
                    <div>
                        <div class="detail-item">
                            <span class="detail-label">Email:</span>
                            <span class="detail-value"><?= htmlspecialchars($user_data['email']) ?></span>
                        </div>
                        <?php if (!empty($user_data['contact_number'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Phone:</span>
                            <span class="detail-value"><?= htmlspecialchars($user_data['contact_number']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if (!empty($user_data['address'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Address:</span>
                            <span class="detail-value"><?= htmlspecialchars($user_data['address']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user_data['city']) || !empty($user_data['zip_code'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Location:</span>
                            <span class="detail-value">
                                <?= htmlspecialchars($user_data['city']) ?>
                                <?php if (!empty($user_data['zip_code'])) echo ', ' . htmlspecialchars($user_data['zip_code']) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

                <!-- Edit Profile Modal -->
    <div class="edit-profile-modal" id="editProfileModal">
        <div class="edit-profile-content">
            <span class="edit-profile-close-btn" id="editProfileCloseBtn">&times;</span>
            <form class="edit-profile-form" method="POST" action="profile.php">
                <h3>Edit Your Profile</h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="contact_number">Phone Number</label>
                    <input type="tel" id="contact_number" name="contact_number" value="<?= htmlspecialchars($user_data['contact_number'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address"><?= htmlspecialchars($user_data['address'] ?? '') ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" value="<?= htmlspecialchars($user_data['city'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="zip_code">ZIP Code</label>
                        <input type="text" id="zip_code" name="zip_code" value="<?= htmlspecialchars($user_data['zip_code'] ?? '') ?>">
                    </div>
                </div>
                
                <button type="submit" name="update_profile" class="edit-profile-submit-btn">
                    Update Profile
                </button>
            </form>
        </div>
    </div>

            <div class="profile-stats">
                <div class="stat-card">
                    <i class="fas fa-recycle"></i>
                    <h3><?= htmlspecialchars($stats_data['items_recycled'] ?? 0) ?></h3>
                    <p>Items Recycled</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-hand-holding-heart"></i>
                    <h3><?= htmlspecialchars($stats_data['items_donated'] ?? 0) ?></h3>
                    <p>Items Donated</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-tasks"></i>
                    <h3><?= htmlspecialchars($stats_data['projects_completed'] ?? 0) ?></h3>
                    <p>Projects Completed</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-star"></i>
                    <h3><?= htmlspecialchars($user_data['points'] ?? 0) ?></h3>
                    <p>Eco Points</p>
                </div>
            </div>

            <div class="profile-section">
                <div class="section-header">
                    <h3>Eco Badges</h3>
                    <a href="achievements.php" class="view-all">View All</a>
                </div>
                
                <?php
                // Fetch all available badges
                $all_badges_query = $conn->query("SELECT * FROM badges ORDER BY points_required");
                $all_badges = [];
                while ($row = $all_badges_query->fetch_assoc()) {
                    $all_badges[] = $row;
                }
                
                // Check which badges user has earned
                $earned_badges = [];
                if (!empty($badges)) {
                    foreach ($badges as $badge) {
                        $earned_badges[] = $badge['badge_name'];
                    }
                }
                ?>
                
                <div class="badges-grid">
                    <?php foreach ($all_badges as $badge): 
                        $is_earned = in_array($badge['badge_name'], $earned_badges);
                        $progress = min(100, ($user_data['points'] / $badge['points_required']) * 100);
                    ?>
                    <div class="badge-item <?php echo $is_earned ? 'earned' : 'locked'; ?>">
                        <div class="badge-icon">
                            <i class="<?php echo htmlspecialchars($badge['icon']); ?>"></i>
                        </div>
                        <h4><?php echo htmlspecialchars($badge['badge_name']); ?></h4>
                        <p><?php echo htmlspecialchars($badge['description']); ?></p>
                        
                        <?php if (!$is_earned): ?>
                        <div class="badge-progress">
                            <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                        <small><?php echo $user_data['points']; ?> / <?php echo $badge['points_required']; ?> points</small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="profile-section">
                <div class="section-header">
                    <h3>Recent Activity</h3>
                    <a href="#" class="view-all">View All</a>
                </div>
                <?php if (!empty($activities)): ?>
                <ul class="recent-activity">
                    <?php foreach ($activities as $activity): ?>
                    <li class="activity-item">
                        <div class="activity-icon">
                            <i class="<?= getActivityIcon($activity['activity_type']) ?>"></i>
                        </div>
                        <div class="activity-content">
                            <h4><?= htmlspecialchars($activity['description']) ?></h4>
                            <?php if (!empty($activity['points_earned'])): ?>
                            <p>Earned <?= $activity['points_earned'] ?> points</p>
                            <?php endif; ?>
                            <div class="activity-time"><?= formatDate($activity['created_at']) ?></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="no-data-message">
                    <i class="fas fa-history"></i>
                    <p>No recent activities. Start recycling, donating, or joining projects to see your activity here!</p>
                </div>
                <?php endif; ?>
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

            // Edit Profile Modal Functionality
        const editProfileBtn = document.getElementById('editProfileBtn');
        const editProfileModal = document.getElementById('editProfileModal');
        const editProfileCloseBtn = document.getElementById('editProfileCloseBtn');

        editProfileBtn.addEventListener('click', () => {
            editProfileModal.style.display = 'flex';
        });

        editProfileCloseBtn.addEventListener('click', () => {
            editProfileModal.style.display = 'none';
        });

        window.addEventListener('click', (event) => {
            if (event.target === editProfileModal) {
                editProfileModal.style.display = 'none';
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