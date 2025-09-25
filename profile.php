<?php

session_start();
require_once 'config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
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
// MySQLi version
$check_badges = $conn->query("SELECT COUNT(*) as count FROM badges");
$badge_count_row = $check_badges->fetch_assoc();
if ($badge_count_row && $badge_count_row['count'] == 0) {
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
$result = $check_activities->get_result();
$activity_count_row = $result->fetch_assoc();
$activity_count = $activity_count_row ? $activity_count_row['count'] : 0;

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
$result = $check_stats->get_result();
$stats_count_row = $result->fetch_assoc();
$stats_count = $stats_count_row ? $stats_count_row['count'] : 0;

if ($stats_count == 0) {
    $conn->query("INSERT INTO user_stats (user_id, projects_completed, achievements_earned, badges_earned, items_donated, items_recycled) 
                  VALUES ($user_id, 1, 1, 1, 1, 1)");
}

// Fetch user data


$user_query = $conn->prepare("SELECT user_id, email, first_name, middle_name, last_name, contact_number, address, city, zip_code, created_at, points FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$result = $user_query->get_result();
$user_data = $result->fetch_assoc();

// Fetch user stats


$stats_query = $conn->prepare("SELECT projects_completed, achievements_earned, badges_earned, items_donated, items_recycled FROM user_stats WHERE user_id = ?");
$stats_query->bind_param("i", $user_id);
$stats_query->execute();
$result = $stats_query->get_result();
$stats_data = $result->fetch_assoc();

// Calculate level based on points
$level = floor(($user_data['points'] ?? 0) / 25);

// Fetch user badges

$badges = [];
$badges_query = $conn->prepare("
    SELECT b.badge_name, b.description, b.icon 
    FROM user_badges ub 
    JOIN badges b ON ub.badge_id = b.badge_id 
    WHERE ub.user_id = ? 
    LIMIT 5
");
$badges_query->bind_param("i", $user_id);
$badges_query->execute();
$result = $badges_query->get_result();
while ($row = $result->fetch_assoc()) {
    $badges[] = $row;
}

// If no badges but user has points, auto-assign appropriate badges
if (empty($badges) && isset($user_data['points'])) {
    $points = $user_data['points'];
    $auto_badges = $conn->prepare("SELECT badge_id FROM badges WHERE points_required <= ? ORDER BY points_required DESC");
    $auto_badges->execute([$points]);
    $auto_badges = $auto_badges->get_result();
    while ($badge = $auto_badges->fetch_assoc()) {
        // Assign badge to user
        $assign_badge = $conn->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)");
        $assign_badge->execute([$user_id, $badge['badge_id']]);
        // Add to badges array for display
        $badge_info = $conn->prepare("SELECT badge_name, description, icon FROM badges WHERE badge_id = ?");
        $badge_info->execute([$badge['badge_id']]);
        $badge_result = $badge_info->get_result();
        if ($badge_data = $badge_result->fetch_assoc()) {
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
$activities_query->bind_param("i", $user_id);
$activities_query->execute();
$result = $activities_query->get_result();
while ($row = $result->fetch_assoc()) {
    $activities[] = $row;
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Open Sans', sans-serif;
        }

        body {
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }

        h1, h2, h3, h4 {
            font-family: 'Montserrat', sans-serif;
        }

        .container {
            display: grid;
            grid-template-columns: 250px 1fr;
            max-width: 1200px;
            margin: 0 auto;
            gap: 20px;
            padding: 0 20px;
        }

        header {
            grid-column: 1 / -1;
            background-color: #82AA52;
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo img {
            width: 50px;
            height: 50px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            position: relative;
            cursor: pointer;
        }

        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            overflow: hidden;
        }

        .profile-pic img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-name {
            font-weight: 600;
            margin-right: 5px;
        }

        .dropdown-arrow {
            transition: transform 0.3s;
        }

        .user-profile.active .dropdown-arrow {
            transform: rotate(180deg);
        }

        .profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background-color: white;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 200px;
            z-index: 100;
            display: none;
            overflow: hidden;
        }

        .user-profile.active .profile-dropdown {
            display: block;
        }

        .dropdown-item {
            padding: 10px 15px;
            color: #333;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: background-color 0.2s;
        }

        .dropdown-item i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .dropdown-item:hover {
            background-color: #f5f5f5;
        }

        .dropdown-divider {
            height: 1px;
            background-color: #eee;
            margin: 5px 0;
        }

        .sidebar {
            background-color: #F6FFEB;
            padding: 30px 20px;
            box-shadow: 3px 0 10px rgba(0,0,0,0.1);
            height: fit-content;
            border-radius: 10px;
            position: sticky;
            top: 20px;
        }

        .sidebar nav ul {
            list-style: none;
        }

        .sidebar nav li {
            margin-bottom: 15px;
        }

        .sidebar nav a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }


        .sidebar nav a.active,
        .sidebar nav a:hover {
            background-color: #e0f0d8;
            color: #2e8b57;
        }

        .sidebar nav a i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        .main-content {
            padding: 30px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 25px;
            border: 5px solid #F6FFEB;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #82AA52;
            color: white;
            font-size: 48px;
            font-weight: bold;
        }

        .profile-info h2 {
            color: #82AA52;
            font-size: 28px;
            margin-bottom: 5px;
        }

        .profile-info p {
            color: #666;
            margin-bottom: 10px;
        }

        .profile-level {
            display: inline-block;
            background-color: #F6FFEB;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            color: #82AA52;
            font-size: 14px;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background-color: #F6FFEB;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .stat-card i {
            font-size: 24px;
            color: #82AA52;
            margin-bottom: 10px;
        }

        .stat-card h3 {
            font-size: 32px;
            color: #82AA52;
            margin-bottom: 5px;
        }

        .stat-card p {
            color: #666;
            font-size: 14px;
        }

        .profile-section {
            margin-bottom: 40px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .section-header h3 {
            color: #82AA52;
            font-size: 22px;
            margin: 0;
        }

        .view-all {
            color: #82AA52;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }

        .view-all:hover {
            text-decoration: underline;
        }

        .badges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 15px;
        }

        .badge-item {
            background-color: #F6FFEB;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .badge-icon {
            width: 60px;
            height: 60px;
            background-color: #82AA52;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            color: white;
            font-size: 24px;
        }

        .badge-item h4 {
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }

        .badge-item p {
            font-size: 12px;
            color: #666;
        }

                /* Badge states */
        .badge-item.locked {
            opacity: 0.7;
            position: relative;
        }

        .badge-item.locked::after {
            content: '🔒';
            position: absolute;
            top: 5px;
            right: 5px;
            font-size: 14px;
        }

        .badge-item.earned .badge-icon {
            background: linear-gradient(135deg, #82AA52, #4CAF50);
            box-shadow: 0 4px 10px rgba(130, 170, 82, 0.3);
        }

        /* Progress bar for badges */
        .badge-progress {
            height: 5px;
            background-color: #e0e0e0;
            border-radius: 5px;
            margin-top: 8px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background-color: #82AA52;
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .badge-item small {
            display: block;
            margin-top: 5px;
            font-size: 11px;
            color: #888;
        }

        .badge-item {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .badge-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .recent-activity {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background-color: #F6FFEB;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: #82AA52;
        }

        .activity-content {
            flex: 1;
        }

        .activity-content h4 {
            font-size: 16px;
            margin-bottom: 5px;
            color: #333;
        }

        .activity-content p {
            font-size: 14px;
            color: #666;
        }

        .activity-time {
            font-size: 12px;
            color: #999;
        }

        .edit-profile-btn {
            background-color: #82AA52;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .edit-profile-btn:hover {
            background-color: #6d8f45;
        }

        .user-details {
            background-color: #F6FFEB;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .user-details h3 {
            color: #82AA52;
            margin-bottom: 15px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .detail-item {
            display: flex;
            margin-bottom: 10px;
        }

        .detail-label {
            font-weight: 600;
            min-width: 120px;
            color: #555;
        }

        .detail-value {
            color: #333;
        }

        /* Feedback System */
        .feedback-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            background-color: #82AA52;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            z-index: 100;
            transition: all 0.3s ease;
        }

        .feedback-btn:hover {
            transform: scale(1.1);
            background-color: #6d8f45;
        }

        .feedback-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .feedback-content {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            position: relative;
        }

        .feedback-close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #777;
        }

        .feedback-close-btn:hover {
            color: #333;
        }

        .feedback-form h3 {
            color: #82AA52;
            margin-bottom: 20px;
            font-family: 'Montserrat', sans-serif;
        }

        .emoji-rating {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
        }

        .emoji-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            transition: transform 0.2s;
            padding: 5px;
            border-radius: 50%;
        }

        .emoji-option:hover {
            transform: scale(1.2);
            background-color: #f0f7e8;
        }

        .emoji-option.selected {
            transform: scale(1.3);
            background-color: #e0f0d8;
        }

        .emoji {
            font-size: 30px;
            margin-bottom: 5px;
        }

        .emoji-label {
            font-size: 12px;
            color: #666;
        }

        .feedback-detail {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .feedback-form textarea {
            width: 100%;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: vertical;
            min-height: 150px;
            margin-bottom: 20px;
            font-family: 'Open Sans', sans-serif;
        }

        .feedback-submit-btn {
            background-color: #82AA52;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s;
            width: 100%;
        }

        .feedback-submit-btn:hover {
            background-color: #6d8f45;
        }

        .thank-you-message {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .thank-you-message h3 {
            color: #82AA52;
            margin-bottom: 15px;
        }

        .thank-you-emoji {
            font-size: 50px;
            margin-bottom: 20px;
        }

        .error-message {
            color: #e74c3c;
            font-size: 13px;
            margin-top: 5px;
            display: none;
        }

        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-left: 10px;
        }

                /* Add these new styles for the edit profile modal */
        .edit-profile-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .edit-profile-content {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            position: relative;
            max-height: 80vh;
            overflow-y: auto;
        }

        .edit-profile-close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #777;
        }

        .edit-profile-close-btn:hover {
            color: #333;
        }

        .edit-profile-form h3 {
            color: #82AA52;
            margin-bottom: 20px;
            font-family: 'Montserrat', sans-serif;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: 'Open Sans', sans-serif;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .edit-profile-submit-btn {
            background-color: #82AA52;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s;
            width: 100%;
        }

        .edit-profile-submit-btn:hover {
            background-color: #6d8f45;
        }

        .success-message {
            color: #82AA52;
            font-size: 14px;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #F6FFEB;
            border-radius: 4px;
            text-align: center;
        }

                .back-navigation {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .back-button {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: #82AA52;
            font-weight: 600;
            margin-right: 15px;
            transition: color 0.3s;
        }
        
        .back-button:hover {
            color: #6d8f45;
        }
        
        .back-button i {
            margin-right: 8px;
        }
        
        .page-title {
            font-size: 24px;
            color: #82AA52;
            font-weight: 700;
            margin: 0;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive adjustments */
        @media (max-width: 900px) {
            .container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .profile-stats {
                grid-template-columns: 1fr 1fr;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-avatar {
                margin-right: 0;
                margin-bottom: 20px;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            .profile-stats {
                grid-template-columns: 1fr;
            }
            
            .badges-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            }
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
                    <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
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