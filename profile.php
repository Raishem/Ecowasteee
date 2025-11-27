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
        $_SESSION['success_message'] = $success_message;
        header("Location: profile.php");
        exit();
    } else {
        $error_message = "Error updating profile: " . $conn->error;
    }
}

// ----------------------
// Total Eco Points from database
$stmt = $conn->prepare("SELECT total_points FROM user_stats WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$total_claimed_points = (int)($res->fetch_assoc()['total_points'] ?? 0);
$stmt->close();


// Update user's points in the users table
$conn->query("UPDATE users SET points = $total_claimed_points WHERE user_id = $user_id");


// ----------------------
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
        error_log("Table creation error: " . $e->getMessage());
    }
}

// Insert sample badges if none exist
$check_badges = $conn->query("SELECT COUNT(*) as count FROM badges");
if ($check_badges->fetch_assoc()['count'] == 0) {
    $sample_badges = [
        "('EcoWaste Beginner', 'Completed your first eco activity', 'fas fa-seedling', 10)",
        "('Rising Donor', 'Completed your first donation', 'fas fa-hand-holding-heart', 10)",
        "('Eco Beginner', 'Started your first recycling project', 'fas fa-recycle', 10)",
        "('Eco Star', 'Completed a recycling project', 'fas fa-star', 15)",
        "('Donation Starter', 'Donated 1 item', 'fas fa-hand-holding-heart', 20)",
        "('Donation Hero', 'Donated 5+ items', 'fas fa-heart', 75)",
        "('Donation Champion', 'Donated 15+ items', 'fas fa-gift', 225)",
        "('Generous Giver', 'Completed 20 donations', 'fas fa-hands-helping', 400)",
        "('Charity Champion', 'Completed 30 donations', 'fas fa-award', 600)",
        "('Recycling Starter', 'Recycled 1 item', 'fas fa-recycle', 10)",
        "('Recycling Pro', 'Recycled 5+ items', 'fas fa-recycle', 50)",
        "('Recycling Expert', 'Recycled 15+ items', 'fas fa-recycle', 150)",
        "('Zero Waste Hero', 'Created 25 recycling projects', 'fas fa-project-diagram', 375)",
        "('Earth Saver', 'Created 30 recycling projects', 'fas fa-globe', 450)",
        "('Eco Pro', 'Completed 20 recycling projects', 'fas fa-seedling', 300)",
        "('Eco Legend', 'Completed 30 recycling projects', 'fas fa-trophy', 450)",
        "('EcoWaste Rookie', 'Earned 50+ points', 'fas fa-star', 50)",
        "('EcoWaste Master', 'Earned 100+ points', 'fas fa-medal', 100)",
        "('EcoWaste Warrior', 'Earned 200+ points', 'fas fa-trophy', 200)",
        "('EcoWaste Legend', 'Earned 500+ points', 'fas fa-crown', 500)"
    ];
    $conn->query("INSERT INTO badges (badge_name, description, icon, points_required) VALUES " . implode(',', $sample_badges));
}

// Ensure user has stats record
$check_stats = $conn->prepare("SELECT COUNT(*) as count FROM user_stats WHERE user_id = ?");
$check_stats->bind_param("i", $user_id);
$check_stats->execute();
$stats_count = $check_stats->get_result()->fetch_assoc()['count'];
if ($stats_count == 0) {
    $conn->query("INSERT INTO user_stats (user_id, projects_completed, achievements_earned, badges_earned, items_donated, items_recycled) 
                  VALUES ($user_id, 0, 0, 0, 0, 0)");
}

// Fetch user data
$user_query = $conn->prepare("SELECT user_id, email, first_name, middle_name, last_name, contact_number, address, city, zip_code, created_at, points FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Fetch stats once
$stats_query = $conn->prepare("
    SELECT projects_completed, projects_created, achievements_earned, badges_earned, items_donated, items_recycled 
    FROM user_stats 
    WHERE user_id = ?
");
$stats_query->bind_param("i", $user_id);
$stats_query->execute();
$stats_result = $stats_query->get_result();
$stats_data = $stats_result->fetch_assoc();

// Ensure stats are integers
$stats_data = [
    'items_donated'      => (int)($stats_data['items_donated'] ?? 0),
    'items_recycled'     => (int)($stats_data['items_recycled'] ?? 0),
    'projects_created'   => (int)($stats_data['projects_created'] ?? 0),
    'projects_completed' => (int)($stats_data['projects_completed'] ?? 0),
];

// Calculate level based on points
$level = 1 + floor(($user_data['points'] ?? 0) / 25);

// ----------------------
// BADGE LOGIC (UPDATED)
// ----------------------

// Fetch all badges from DB
$all_badges = [];
$result = $conn->query("SELECT * FROM badges ORDER BY badge_id ASC");
while ($row = $result->fetch_assoc()) {
    $all_badges[$row['badge_id']] = $row;
}

// Fetch badges already earned by user
$user_badges = [];
$stmt = $conn->prepare("SELECT badge_id FROM user_badges WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $user_badges[$row['badge_id']] = true;
}

// ---------------------------
// Compute action-based stats
// ---------------------------

// Total items donated
$stmt = $conn->prepare("SELECT SUM(quantity) as total_items FROM donations WHERE donor_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$total_items_donated = (int)($res->fetch_assoc()['total_items'] ?? 0);
$stmt->close();

// Total donation posts
$stmt = $conn->prepare("SELECT COUNT(*) as donation_posts FROM donations WHERE donor_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$total_donation_posts = (int)($res->fetch_assoc()['donation_posts'] ?? 0);
$stmt->close();

// Total eco activities
$stmt = $conn->prepare("SELECT COUNT(*) as activity_count FROM user_activities WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$total_activities = (int)($res->fetch_assoc()['activity_count'] ?? 0);
$stmt->close();

// ---------------------------
// ASSIGN BADGES BASED ON POINTS & ACTIONS
// ---------------------------
$badge_progress = [];
foreach ($all_badges as $badge) {
    $earned = false;

    switch ($badge['badge_name']) {

        // Points-based badges
        case "EcoWaste Rookie":
        case "EcoWaste Master":
        case "EcoWaste Warrior":
        case "EcoWaste Legend":
            $current = (int)($user_data['points'] ?? 0); // Use actual user points
            $required = (int)$badge['points_required'];
            $earned = $current >= $required;
            break;


        // Action-based badges
        case "EcoWaste Beginner": $earned = $total_activities >= 1; $current = $total_activities; $required = 1; break;
        case "Donation Starter": $earned = $total_items_donated >= 1; $current = $total_items_donated; $required = 1; break;
        case "Rising Donor": $earned = $total_donation_posts >= 1; $current = $total_donation_posts; $required = 1; break;
        case "Donation Hero": $earned = $total_items_donated >= 5; $current = $total_items_donated; $required = 5; break;
        case "Donation Champion": $earned = $total_items_donated >= 15; $current = $total_items_donated; $required = 15; break;
        case "Generous Giver": $earned = $total_donation_posts >= 20; $current = $total_donation_posts; $required = 20; break;
        case "Charity Champion": $earned = $total_donation_posts >= 30; $current = $total_donation_posts; $required = 30; break;
        case "Eco Star": $earned = $stats_data['projects_completed'] >= 1; $current = $stats_data['projects_completed']; $required = 1; break;
        case "Recycling Starter": $earned = $stats_data['items_recycled'] >= 1; $current = $stats_data['items_recycled']; $required = 1; break;
        case "Recycling Pro": $earned = $stats_data['items_recycled'] >= 10; $current = $stats_data['items_recycled']; $required = 10; break;
        case "Recycling Expert": $earned = $stats_data['items_recycled'] >= 15; $current = $stats_data['items_recycled']; $required = 15; break;
        case "Project Master": $earned = $stats_data['projects_completed'] >= 3; $current = $stats_data['projects_completed']; $required = 3; break;
        case "Eco Pro": $earned = $stats_data['projects_completed'] >= 20; $current = $stats_data['projects_completed']; $required = 20; break;
        case "Eco Legend": $earned = $stats_data['projects_completed'] >= 30; $current = $stats_data['projects_completed']; $required = 30; break;
        case "Zero Waste Hero": $earned = $stats_data['projects_created'] >= 25; $current = $stats_data['projects_created']; $required = 25; break;
        case "Earth Saver": $earned = $stats_data['projects_created'] >= 30; $current = $stats_data['projects_created']; $required = 30; break;

        default:
            $earned = false;
            $current = 0;
            $required = 1;
            break;
    }

    // Insert badge if earned and not already added
    if ($earned && !isset($user_badges[$badge['badge_id']])) {
        $stmt = $conn->prepare("INSERT INTO user_badges (user_id, badge_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $badge['badge_id']);
        $stmt->execute();
        $stmt->close();
        $user_badges[$badge['badge_id']] = true;

        // Insert points for badge into user_activities
        $points_for_badge = (int)$badge['points_required'];
        $desc = "Earned badge: {$badge['badge_name']}";
        $stmt = $conn->prepare("
            INSERT INTO user_activities (user_id, activity_type, description, points_earned)
            VALUES (?, 'badge', ?, ?)
        ");
        $stmt->bind_param("isi", $user_id, $desc, $points_for_badge);
        $stmt->execute();
        $stmt->close();
    }

    // Save progress values for badge display
    $badge_progress[$badge['badge_id']] = [
        'current' => $current,
        'required' => $required
    ];
}

// Update badges earned count
$conn->query("UPDATE user_stats SET badges_earned = " . count($user_badges) . " WHERE user_id = $user_id");

// ----------------------
// Fetch activities
// ----------------------
$activities = [];

// Donations
$stmt = $conn->prepare("SELECT item_name, quantity, donated_at FROM donations WHERE donor_id = ? ORDER BY donated_at DESC LIMIT 10");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $activities[] = [
        'activity_type' => 'donation',
        'description' => "You donated {$row['quantity']} {$row['item_name']}",
        'points_earned' => null,
        'created_at' => $row['donated_at']
    ];
}

// Badges
$stmt = $conn->prepare("SELECT b.badge_name, ub.earned_date FROM user_badges ub JOIN badges b ON ub.badge_id = b.badge_id WHERE ub.user_id = ? ORDER BY ub.earned_date DESC LIMIT 10");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $activities[] = [
        'activity_type' => 'badge',
        'description' => "Earned badge: {$row['badge_name']}",
        'points_earned' => null,
        'created_at' => $row['earned_date']
    ];
}

// Projects
$stmt = $conn->prepare("SELECT project_name, created_at, status FROM projects WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $status = strtolower($row['status']);
    $activities[] = [
        'activity_type' => 'project',
        'description' => ucfirst($status) . " project: {$row['project_name']}",
        'points_earned' => null,
        'created_at' => $row['created_at']
    ];
}

// Sort by newest first and limit to 10
usort($activities, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
$activities = array_slice($activities, 0, 10);

// ----------------------
// Helper functions
// ----------------------
function getActivityIcon($type) {
    switch ($type) {
        case 'recycling': return 'fas fa-recycle';
        case 'donation': return 'fas fa-hand-holding-heart';
        case 'badge': return 'fas fa-trophy';
        case 'project': return 'fas fa-project-diagram';
        default: return 'fas fa-star';
    }
}

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
                    <h3><?= htmlspecialchars($total_claimed_points) ?></h3>
                    <p>Eco Points</p>
                </div>
            </div>


            <div class="profile-section">
                <div class="section-header">
                    <h3>Eco Badges</h3>
                    <a href="#" class="view-all" id="toggleBadges">View All</a>
                </div>

                <?php
                // ----------------------
                // Badges Logic
                // ----------------------

                // Update badges earned count in stats
                $conn->query("UPDATE user_stats SET badges_earned = " . count($user_badges) . " WHERE user_id = $user_id");
                ?>

                <div class="badges-grid" style="display:flex; flex-wrap:wrap; gap:15px;">
                <?php
                    $badge_count = 0;
                    foreach ($all_badges as $badge_id => $badge):
                        $is_earned = isset($user_badges[$badge_id]);
                        $badge_count++;
                        $extra_class = $badge_count > 5 ? 'extra-badge hidden-badge' : '';

                        // Determine progress for badges
                        $current = $badge_progress[$badge_id]['current'] ?? 0;
                        $required = $badge_progress[$badge_id]['required'] ?? 1;

                        // Adjust current based on badge type
                        switch ($badge['badge_name']) {

                        // Points-based badges
                        case "EcoWaste Rookie":
                        case "EcoWaste Master":
                        case "EcoWaste Warrior":
                        case "EcoWaste Legend":
                            $current = $user_data['points'];  // <-- use actual points
                            break;

                        // Donation badges by items
                        case "Rising Donor":
                        case "Donation Starter":
                        case "Donation Hero":
                        case "Donation Champion":
                            $current = $total_items_donated;
                            break;

                        // Donation badges by posts
                        case "Generous Giver":
                        case "Charity Champion":
                            $current = $total_donation_posts;
                            break;

                        // Project creation badges
                        case "Eco Beginner":
                        case "Eco Builder":
                        case "Nature Keeper":
                        case "Conservation Expert":
                        case "Zero Waste Hero":
                        case "Earth Saver":
                            $current = $stats_data['projects_created'];
                            break;

                        // Project completion badges
                        case "Eco Star":
                        case "Eco Warrior":
                        case "Eco Elite":
                        case "Eco Pro":
                        case "Eco Master":
                        case "Eco Legend":
                            $current = $stats_data['projects_completed'];
                            break;

                        // Recycling badges
                        case "Recycling Starter":
                        case "Recycling Pro":
                        case "Recycling Expert":
                            $current = $stats_data['items_recycled'];
                            break;

                        default:
                            $current = 0;
                            break;
                    }


                        $progress = min(100, ($current / max(1, $required)) * 100);
                ?>
                <div class="badge-item <?= $is_earned ? 'earned' : 'locked'; ?> <?= $extra_class; ?>" 
                    style="<?= $badge_count > 5 ? 'display:none;' : ''; ?> 
                            border:1px solid #ccc; border-radius:10px; padding:10px; width:180px; text-align:center;">
                    
                    <div class="badge-icon" style="font-size:24px; margin-bottom:8px;">
                        <i class="<?= htmlspecialchars($badge['icon']); ?>" 
                        style="<?= $is_earned ? 'color:gold;' : 'color:#aaa;' ?>"></i>
                    </div>
                    
                    <h4 style="margin-bottom:5px;"><?= htmlspecialchars($badge['badge_name']); ?></h4>
                    <p style="font-size:13px; margin-bottom:5px;"><?= htmlspecialchars($badge['description']); ?></p>
                    
                    <?php if (!$is_earned): ?>
                        <div class="badge-progress" style="background:#eee; border-radius:5px; height:8px; overflow:hidden; margin-bottom:5px;">
                            <div class="progress-bar" style="width: <?= $progress; ?>%; background:#4caf50; height:100%;"></div>
                        </div>
                        <small style="font-size:12px; color:#555;"><?= $current ?> / <?= $required ?> completed</small>
                    <?php else: ?>
                        <small style="font-size:12px; color:gold;">Earned!</small>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
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
                    <p>No recent activities. </p>
                    <p>Start recycling, donating, or joining projects to see your activity here!</p>
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
document.addEventListener('DOMContentLoaded', function() {

    // -------------------------
    // User Profile Dropdown
    // -------------------------
    const userProfile = document.getElementById('userProfile');
    if(userProfile){
        userProfile.addEventListener('click', function() {
            this.classList.toggle('active');
        });
        document.addEventListener('click', function(event) {
            if (!userProfile.contains(event.target)) {
                userProfile.classList.remove('active');
            }
        });
    }

    // -------------------------
    // Badge Toggle
    // -------------------------
    const toggleBtn = document.getElementById('toggleBadges');
    if(toggleBtn){
        toggleBtn.addEventListener('click', function(event) {
            event.preventDefault(); // prevent page jump
            const hiddenBadges = document.querySelectorAll('.extra-badge'); // fixed selector
            hiddenBadges.forEach(b => {
                b.style.display = b.style.display === 'none' || b.style.display === '' ? 'block' : 'none';
            });
            toggleBtn.textContent = toggleBtn.textContent === 'View All' ? 'Hide' : 'View All';
        });
    }

    // -------------------------
    // Edit Profile Modal
    // -------------------------
    const editProfileBtn = document.getElementById('editProfileBtn');
    const editProfileModal = document.getElementById('editProfileModal');
    const editProfileCloseBtn = document.getElementById('editProfileCloseBtn');

    if(editProfileBtn && editProfileModal && editProfileCloseBtn){
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
    }

    // -------------------------
    // Feedback Modal
    // -------------------------
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

    if(feedbackBtn && feedbackModal && feedbackCloseBtn && feedbackForm){
        // Emoji selection
        emojiOptions.forEach(option => {
            option.addEventListener('click', () => {
                emojiOptions.forEach(opt => opt.classList.remove('selected'));
                option.classList.add('selected');
                selectedRating = option.getAttribute('data-rating');
                ratingError.style.display = 'none';
            });
        });

        // Submit feedback
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
                    closeFeedbackModal();
                }, 3000);

            }, 1500);
        });

        // Open/Close feedback modal
        feedbackBtn.addEventListener('click', () => {
            feedbackModal.style.display = 'flex';
        });
        feedbackCloseBtn.addEventListener('click', closeFeedbackModal);
        window.addEventListener('click', (event) => {
            if (event.target === feedbackModal) {
                closeFeedbackModal();
            }
        });
    }

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