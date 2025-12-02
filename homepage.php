<?php
session_start();
require_once 'config.php';

$conn = getDBConnection();

// Check login status
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$donor_id = $_SESSION['user_id'];
$image_paths_json = null;

/* ----------------------------
   Handle comment submission
---------------------------- */
/* ----------------------------
   Handle comment submission (supports replies)
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' 
    && isset($_POST['comment_text']) 
    && isset($_POST['donation_id']) 
    && !isset($_POST['submit_request_donation'])) {

    $comment_text = trim($_POST['comment_text']);
    if ($comment_text === '') {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Empty comment.']);
            exit();
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $donation_id = (int)$_POST['donation_id'];
    $user_id = $_SESSION['user_id'];
    $parent_id = (isset($_POST['parent_id']) && $_POST['parent_id'] !== '') ? (int)$_POST['parent_id'] : null;
    $created_at = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        INSERT INTO comments (donation_id, user_id, comment_text, parent_id, created_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    // parent_id can be null — bind as integer or null (use 'i' with null handled as NULL via bind_param wrapper)
    if ($parent_id === null) {
        // bind null as NULL (must use 's' for null string then set to null in query?) easiest: use 'i' and pass null via null cast
        $stmt->bind_param("iisis", $donation_id, $user_id, $comment_text, $parent_id, $created_at);
    } else {
        $stmt->bind_param("iisis", $donation_id, $user_id, $comment_text, $parent_id, $created_at);
    }

    if ($stmt->execute()) {
        $comment_id = $stmt->insert_id;

        // If AJAX, return JSON with immediate UI data
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // fetch user's name for response
            $u = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
            $u->bind_param("i", $user_id);
            $u->execute();
            $userRow = $u->get_result()->fetch_assoc();

            $user_name = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
            $user_initial = strtoupper(substr(($userRow['first_name'] ?? 'U'), 0, 1));

            // created_at in Manila ISO for JS
            $iso = (new DateTime($created_at, new DateTimeZone('Asia/Manila')))->format(DateTime::ATOM);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'comment_id' => (int)$comment_id,
                'user_name' => htmlspecialchars($user_name),
                'user_initial' => $user_initial,
                'comment_text' => htmlspecialchars($comment_text),
                'created_iso' => $iso,
                'parent_id' => $parent_id
            ]);
            exit();
        }

        // non-AJAX fallback
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to save comment.']);
            exit();
        }
        die('Error: Failed to post comment. ' . $stmt->error);
    }
}



/* ----------------------------
   Handle donation form submission
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wasteType']) && !isset($_POST['submit_request_donation'])) {
    // Validate required fields
    if (empty($_POST['wasteType']) || empty($_POST['quantity']) || empty($_POST['description'])) {
        $_SESSION['donation_error'] = 'All fields are required.';
        header('Location: homepage.php');
        exit();
    }

    $wasteType = $_POST['wasteType'] === 'Other' ? trim($_POST['otherWaste']) : $_POST['wasteType'];
    $category = htmlspecialchars($_POST['wasteType']);
    $subcategory = !empty($_POST['subcategory']) ? htmlspecialchars($_POST['subcategory']) : null;
    $item_name = $subcategory ? "$subcategory ($category)" : $category;
    $quantity = (int) $_POST['quantity'];
    $description = htmlspecialchars($_POST['description']);
    $donor_id = $_SESSION['user_id'];
    $donated_at = date('Y-m-d H:i:s');
    $image_paths_json = null;

    // Handle image uploads
    $image_paths = array();
    if (isset($_FILES['photos']) && count($_FILES['photos']['name']) > 0) {
        $upload_dir = 'assets/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_count = 0;
        foreach ($_FILES['photos']['name'] as $key => $file_name) {
            if ($file_count >= 4) break;
            if (empty($file_name)) continue;
            if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['photos']['tmp_name'][$key];
                $file_type = mime_content_type($file_tmp);
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                if (in_array($file_type, $allowed_types)) {
                    $unique_file_name = uniqid() . '_' . basename($file_name);
                    $target_file = $upload_dir . $unique_file_name;
                    if (move_uploaded_file($file_tmp, $target_file)) {
                        $image_paths[] = $target_file;
                        $file_count++;
                    }
                }
            }
        }
        if (!empty($image_paths)) {
            $image_paths_json = json_encode($image_paths);
        }
    }

    // Insert into DB
    $stmt = $conn->prepare("INSERT INTO donations (item_name, quantity, total_quantity, category, subcategory, description, donor_id, donated_at, status, image_path) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Available', ?)");
    $total_quantity = $quantity;
    $stmt->bind_param("sisssssss", $item_name, $quantity, $total_quantity, $category, $subcategory, $description, $donor_id, $donated_at, $image_paths_json);
    $stmt->execute();

    // Get donation ID
    $donation_id = $stmt->insert_id;

    // Update stats
    $stats_check = $conn->query("SELECT * FROM user_stats WHERE user_id = $donor_id");
    if ($stats_check->num_rows === 0) {
        $conn->query("INSERT INTO user_stats (user_id, items_donated) VALUES ($donor_id, $quantity)");
    } else {
        $conn->query("UPDATE user_stats SET items_donated = items_donated + $quantity WHERE user_id = $donor_id");
    }

    // ✅ Log activity to user_activities
    $activity_desc = "You donated {$quantity} {$subcategory} ({$category})";
    $points_earned = $quantity * 5; // example points per item
    $activity_stmt = $conn->prepare("
        INSERT INTO user_activities (user_id, activity_type, description, points_earned)
        VALUES (?, 'donation', ?, ?)
    ");
    $activity_stmt->bind_param("isi", $donor_id, $activity_desc, $points_earned);
    $activity_stmt->execute();
    $activity_stmt->close();

    // Redirect with success
    header("Location: homepage.php?donation_success=1");
    exit();

}


/* ----------------------------
   Handle request donation with extra details
   Validations:
     - donation exists
     - cannot request own donation
     - quantity_claim <= available
     - decrement donation.quantity immediately
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request_donation'])) {
    $donation_id = isset($_POST['donation_id']) ? (int)$_POST['donation_id'] : 0;
    $user_id = $_SESSION['user_id'];
    $quantity_claim = max(1, (int)$_POST['quantity_claim']);
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    $urgency_level = isset($_POST['urgency_level']) ? htmlspecialchars($_POST['urgency_level']) : null;

    if ($donation_id <= 0 || $quantity_claim <= 0) {
        $response = ['status' => 'error', 'message' => 'Invalid request data.'];
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    $chk = $conn->prepare("SELECT donor_id, quantity FROM donations WHERE donation_id = ?");
    $chk->bind_param("i", $donation_id);
    $chk->execute();
    $don = $chk->get_result()->fetch_assoc();

    if (!$don) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Donation not found.']);
        exit();
    }

    if ($don['donor_id'] == $user_id) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'You cannot request your own donation.']);
        exit();
    }

    $available = (int)$don['quantity'];
    if ($quantity_claim > $available) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Requested quantity exceeds available.']);
        exit();
    }

    $upd = $conn->prepare("UPDATE donations SET quantity = quantity - ? WHERE donation_id = ? AND quantity >= ?");
    $upd->bind_param("iii", $quantity_claim, $donation_id, $quantity_claim);
    $upd->execute();

    if ($upd->affected_rows === 0) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Donation not available.']);
        exit();
    }

    $conn->query("UPDATE donations SET status = 'Completed' WHERE donation_id = $donation_id AND quantity = 0");

    $conn->query("CREATE TABLE IF NOT EXISTS donation_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        donation_id INT NOT NULL,
        user_id INT NOT NULL,
        quantity_claim INT NOT NULL,
        project_id INT NULL,
        urgency_level VARCHAR(20) DEFAULT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (donation_id),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $ins = $conn->prepare("INSERT INTO donation_requests (donation_id, user_id, quantity_claim, project_id, urgency_level, requested_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $ins->bind_param("iiiis", $donation_id, $user_id, $quantity_claim, $project_id, $urgency_level);
    $ins->execute();

    // ✅ Return JSON if AJAX
    if (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit();
    }

    // fallback redirect for normal usage
    header('Location: ' . $_SERVER['PHP_SELF'] . '?request_success=1');
    exit();
}


//Helper functions and data fetches 

// Function to calculate time ago
function getTimeAgo($timestamp) {
    $currentTime = time();
    $timeDiff = $currentTime - strtotime($timestamp);
    
    if ($timeDiff < 60) return 'just now';
    elseif ($timeDiff < 3600) return floor($timeDiff / 60) . ' min ago';
    elseif ($timeDiff < 86400) return floor($timeDiff / 3600) . ' hour' . (floor($timeDiff / 3600) > 1 ? 's' : '') . ' ago';
    elseif ($timeDiff < 2592000) return floor($timeDiff / 86400) . ' day' . (floor($timeDiff / 86400) > 1 ? 's' : '') . ' ago';
    else return date('M d, Y', strtotime($timestamp));
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

// Fetch available donations (status Available)
$donations = [];
$result = $conn->query("SELECT * FROM donations WHERE status='Available' ORDER BY donated_at DESC");
while ($row = $result->fetch_assoc()) $donations[] = $row;

// Fetch comments per donation
$comments = [];
foreach ($donations as $donation) {
    $donation_id = $donation['donation_id'];
    $comment_result = $conn->query("SELECT c.*, u.first_name FROM comments c 
                                   JOIN users u ON c.user_id = u.user_id 
                                   WHERE c.donation_id = $donation_id 
                                   ORDER BY c.created_at DESC");
    $comments[$donation_id] = [];
    while ($comment_row = $comment_result->fetch_assoc()) {
        $comments[$donation_id][] = $comment_row;
    }
}

// Fetch recycled ideas
$ideas = [];
$result = $conn->query("SELECT * FROM recycled_ideas ORDER BY posted_at DESC LIMIT 5");
while ($row = $result->fetch_assoc()) $ideas[] = $row;

// Fetch user stats
$stats = $conn->query("SELECT * FROM user_stats WHERE user_id = {$user['user_id']}")->fetch_assoc();

// Fetch user's projects (for request dropdown)
$user_projects = [];
$result = $conn->query("SELECT project_id, project_name FROM projects WHERE user_id = {$_SESSION['user_id']}");
while ($row = $result->fetch_assoc()) {
    $user_projects[] = $row;
}

// Fetch user projects (sidebar)
$projects = [];
$result = $conn->query("SELECT * FROM projects WHERE user_id = {$user['user_id']} ORDER BY created_at DESC");
while ($row = $result->fetch_assoc()) $projects[] = $row;

// Fetch leaderboard
$leaders = [];
$result = $conn->query("SELECT user_id, first_name, points FROM users ORDER BY points DESC LIMIT 10");
while ($row = $result->fetch_assoc()) $leaders[] = $row;

/* Helper to render nested comments remains the same if you have it earlier.
   (If you removed it earlier, re-add the render_comments function.) */
function render_comments($comments, $donation_id, $parent_id = NULL) {
    foreach ($comments as $comment) {
        // Normalize parent_id (may be null/0)
        $cParent = isset($comment['parent_id']) && $comment['parent_id'] !== '0' ? $comment['parent_id'] : null;
        if ($cParent == $parent_id) {
            $userInitial = strtoupper(substr(htmlspecialchars($comment['first_name'] ?? ''), 0, 1));

            // Treat stored timestamp as Manila and create ISO
            $createdAt = new DateTime($comment['created_at'], new DateTimeZone('Asia/Manila'));
            $isoTime = $createdAt->format(DateTime::ATOM);

            echo "<li class='comment-item' data-id='" . (int)$comment['comment_id'] . "' data-donation-id='" . (int)$donation_id . "'>";
            echo "<div class='comment-avatar'>{$userInitial}</div>";
            echo "<div class='comment-content'>";
            echo "<div class='comment-author'>" . htmlspecialchars(trim($comment['first_name'] ?? '')) . "</div>";
            echo "<div class='comment-text'>" . nl2br(htmlspecialchars($comment['comment_text'])) . "</div>";
            echo "<div class='comment-time' data-time='" . htmlspecialchars($isoTime) . "'>" . getTimeAgo($comment['created_at']) . "</div>";
            echo "<div class='comment-actions'>";
            echo "<button class='reply-btn'><i class='fas fa-reply'></i> Reply</button>";
            if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $comment['user_id']) {
                echo "<button class='edit-btn'><i class='fas fa-edit'></i> Edit</button>";
                echo "<button class='delete-btn'><i class='fas fa-trash-alt'></i> Delete</button>";
            }
            echo "</div>"; // comment-actions
            echo "</div>"; // comment-content

            // Render any replies recursively
            echo "<ul class='reply-list'>";
            render_comments($comments, $donation_id, $comment['comment_id']);
            echo "</ul>";

            echo "</li>";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/homepage.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .file-count-message {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
            font-weight: 500;
        }
        
        .donation-post {
            padding: 20px;
            border: 1px solid #e8e8e8;
            border-radius: 12px;
            margin-bottom: 20px;
            background-color: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .donation-user-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #3d6a06;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 16px;
            color: #333;
            margin-bottom: 4px;
        }
        
        .donation-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 13px;
            color: #666;
        }
        
        .category {
            font-weight: 500;
        }
        
        .time-ago {
            color: #999;
        }
        
        .quantity-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background-color: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .quantity-label {
            font-weight: 600;
            color: #333;
        }
        
        .quantity-unit {
            color: #666;
            font-size: 14px;
        }
        
        .donation-description {
            margin-bottom: 15px;
            padding: 12px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .donation-description p {
            margin: 0;
            color: #444;
            line-height: 1.5;
        }
        
        .donation-images {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .donation-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .donation-image:hover {
            transform: scale(1.05);
        }
        
        .donation-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .request-btn {
            padding: 10px 20px;
            background-color: #2e8b57;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .request-btn:hover {
            background-color: #3cb371;
        }

        .comments-btn {
            padding: 10px 20px;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .comments-btn:hover {
            background-color: #5a6268;
        }

        .profile-link {
            color: #333;
            text-decoration: none;
            transition: color 0.3s;
            font-weight: 600;
        }

        .profile-link:hover {
            color: #2e8b57;
            text-decoration: underline;
        }

        /* Comments Section Styles */
        .comments-section {
            margin-top: 15px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .comments-list {
            margin-bottom: 15px;
        }

        .comment {
            padding: 12px;
            margin-bottom: 10px;
            background-color: white;
            border-radius: 6px;
            border-left: 3px solid #2e8b57;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .comment-author {
            font-weight: 600;
            color: #2e8b57;
        }

        .comment-time {
            font-size: 12px;
            color: #6c757d;
        }

        .comment-text {
            color: #333;
            margin: 0;
            line-height: 1.4;
        }

        .add-comment {
            margin-top: 15px;
        }

        .comment-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            resize: vertical;
            min-height: 80px;
            font-family: 'Open Sans', sans-serif;
            margin-bottom: 10px;
        }

        .comment-textarea:focus {
            outline: none;
            border-color: #2e8b57;
            box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.1);
        }

        .post-comment-btn {
            padding: 8px 16px;
            background-color: #2e8b57;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s;
        }

        .post-comment-btn:hover {
            background-color: #3cb371;
        }

        .no-comments {
            text-align: center;
            color: #6c757d;
            font-style: italic;
            padding: 20px;
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
                    <?= strtoupper(substr(htmlspecialchars($user['first_name']), 0, 1)) ?>
                </div>
                <span class="profile-name"><?= htmlspecialchars($user['first_name']) ?></span>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                <div class="profile-dropdown">
                    <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
                    <a href="#" class="dropdown-item" id="settingsLink">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </header>

    <div class="container">
        <aside class="sidebar">
            <nav>
                <ul>
                    <li><a href="homepage.php" class="active"><i class="fas fa-home"></i>Home</a></li>
                    <li><a href="browse.php" ><i class="fas fa-search"></i>Browse</a></li>
                    <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-hand-holding-heart"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['password_success'])): ?>
            <div class="alert alert-success" style="margin: 20px; padding: 15px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px;">
                <?php echo htmlspecialchars($_SESSION['password_success']); ?>
                <?php unset($_SESSION['password_success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['password_error'])): ?>
            <div class="alert alert-danger" style="margin: 20px; padding: 15px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px;">
                <?php echo htmlspecialchars($_SESSION['password_error']); ?>
                <?php unset($_SESSION['password_error']); ?>
            </div>
        <?php endif; ?>

            <div class="welcome-card">
                <h2>WELCOME TO ECOWASTE</h2>
                <div class="divider"></div>
                <p>Join our community in making the world a cleaner places</p>
                <div class="btn-container">
                    <button type="button" class="btn" id="donateWasteBtn" role="button" tabindex="0">Donate Waste</button>
                    <a href="start_project.php" class="btn">Start Recycling</a>
                    <a href="learn_more.php" class="btn" style="background-color: #666;">Learn More</a>
                </div>
            </div>
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
    <div class="donation-user-header">
        <div class="user-avatar">
            <?php 
                $donor_stmt = $conn->prepare("SELECT user_id, first_name FROM users WHERE user_id = ?");
                $donor_stmt->bind_param("i", $donation['donor_id']);
                $donor_stmt->execute();
                $donor_result = $donor_stmt->get_result();
                $donor = $donor_result->fetch_assoc();
                $donor_initial = strtoupper(substr(htmlspecialchars($donor['first_name']), 0, 1));
            ?>
            <?= $donor_initial ?>
        </div>
        <div class="user-info">
            <div class="user-name">
                <a href="profile_view.php?user_id=<?= $donor['user_id'] ?>" class="profile-link">
                    <?= htmlspecialchars($donor['first_name']) ?>
                </a>
            </div>
            <div class="donation-meta">
                <span class="category">Category: <?= htmlspecialchars($donation['category']) ?>
                    <?php if (!empty($donation['subcategory'])): ?>
                        → <?= htmlspecialchars($donation['subcategory']) ?>
                    <?php endif; ?>
                </span>
                <span class="time-ago"><?= getTimeAgo($donation['donated_at']) ?></span>
            </div>
        </div>
    </div>
    
    <div class="quantity-info">
        <div class="quantity-label">
        Quantity: <?= htmlspecialchars($donation['quantity']) ?>/<?= htmlspecialchars($donation['total_quantity']) ?>
        </div>

        <div class="quantity-unit">Units</div>
    </div>
    
    <?php if (!empty($donation['description'])): ?>
    <div class="donation-description">
        <p><?= nl2br(htmlspecialchars($donation['description'])) ?></p>
    </div>
    <?php endif; ?>
    
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
                            <input type="hidden" name="donation_id" value="<?= $donation['donation_id'] ?>">
                            <?php if ($donation['donor_id'] != $_SESSION['user_id']): ?>
                            <button type="button" class="request-btn"
        onclick="openRequestPopup(<?= $donation['donation_id'] ?>, '<?= htmlspecialchars($donation['item_name']) ?>', <?= (int)$donation['quantity'] ?>)"
        <?= $donation['quantity'] <= 0 ? 'disabled' : '' ?>>
        Request Donation
    </button>
<?php endif; ?>


                
                        <button class="comments-btn" onclick="toggleComments(this, <?= $donation['donation_id'] ?>)">
                            <i class="fas fa-comment"></i> Comments
                        </button>
                    </div>
    
                    <div class="comments-section" id="comments-<?= $donation['donation_id'] ?>" style="display: none;">
                        <div class="comments-list">
                            <?php
                            if (isset($comments[$donation['donation_id']]) && count($comments[$donation['donation_id']]) > 0) {
                                render_comments($comments[$donation['donation_id']], $donation['donation_id']);
                            } else {
                                echo "<div class='no-comments'>No comments yet. Be the first to comment!</div>";
                            }
                            ?>
                        </div>
                        <div class="add-comment">
                            <form method="POST" action="homepage.php">
                                <textarea name="comment_text" class="comment-textarea" placeholder="Add a comment..." required></textarea>
                                <input type="hidden" name="donation_id" value="<?= $donation['donation_id'] ?>">
                                <button type="submit" class="post-comment-btn">Post Comment</button>
                            </form>
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
        <!-- START OF SAMPLE POSTS -->
        <!-- Sample Post 1: Plastic Planters -->
        <div class="idea-card">
            <div class="idea-header">
                <h3>Plastic Planters</h3>
                <div class="idea-meta">
                    <span class="author">Susha</span>
                    <span class="time-ago">2 days ago</span>
                </div>
            </div>
            <div class="idea-image-container">
                <img src="https://www.theinspirationedit.com/wp-content/uploads/2020/05/DIY-Monster-Planters.jpg" 
                alt="Plastic Bottle Planters" class="idea-image">
            </div>
            <p class="idea-description">Cute recycled plant base using plastic bottles. Perfect for small herbs and succulents!</p>
            <div class="idea-actions">
                <button class="action-btn">Try This Idea</button>
                <span class="comments">34 Comments</span>
            </div>
        </div>

        <!-- Sample Post 2: Glass Jar Lanterns -->
        <div class="idea-card">
            <div class="idea-header">
                <h3>Glass Jar Lanterns</h3>
                <div class="idea-meta">
                    <span class="author">Mark</span>
                    <span class="time-ago">1 week ago</span>
                </div>
            </div>
            <div class="idea-image-container">
                <img src="https://images.immediate.co.uk/production/volatile/sites/10/2018/02/04589917-60b2-43f4-abaa-f5d19350f31e-61437ec.jpg?resize=1200%2C630" 
                alt="Glass Jar Lanterns" class="idea-image">
            </div>
            <p class="idea-description">Transform old glass jars into beautiful outdoor lanterns with LED lights.</p>
            <div class="idea-actions">
                <button class="action-btn">Try This Idea</button>
                <span class="comments">27 Comments</span>
            </div>
        </div>

        <!-- Sample Post 3: Cardboard Organizers -->
        <div class="idea-card">
            <div class="idea-header">
                <h3>Cardboard Organizers</h3>
                <div class="idea-meta">
                    <span class="author">Lisa</span>
                    <span class="time-ago">3 days ago</span>
                </div>
            </div>
            <div class="idea-image-container">
                <img src="https://thefabhome.com/wp-content/uploads/2021/06/18_Ways-to-Organize-with-Cardboard-Boxes.jpg" 
                alt="Cardboard Desk Organizers" class="idea-image">
            </div>
            <p class="idea-description">Create stylish desk organizers from cardboard boxes. Paint and decorate to match your style.</p>
            <div class="idea-actions">
                <button class="action-btn">Try This Idea</button>
                <span class="comments">41 Comments</span>
            </div>
        </div>

        <!-- Sample Post 4: Tin Can Wind Chimes -->
        <div class="idea-card">
            <div class="idea-header">
                <h3>Tin Can Wind Chimes</h3>
                <div class="idea-meta">
                    <span class="author">David</span>
                    <span class="time-ago">5 days ago</span>
                </div>
            </div>
            <div class="idea-image-container">
                <img src="https://www.pictureboxblue.com/wp-content/uploads/2019/04/Tin-can-wind-chime-songbird-sq-s.jpg" 
                alt="Tin Can Wind Chimes" class="idea-image">
            </div>
            <p class="idea-description">Musical wind chimes made from recycled tin cans. Paint them in bright colors for a cheerful garden addition.</p>
            <div class="idea-actions">
                <button class="action-btn">Try This Idea</button>
                <span class="comments">19 Comments</span>
            </div>
        </div>

        <!-- Sample Post 5: Bottle Cap Mosaic -->
        <div class="idea-card">
            <div class="idea-header">
                <h3>Bottle Cap Mosaic</h3>
                <div class="idea-meta">
                    <span class="author">Anna</span>
                    <span class="time-ago">2 weeks ago</span>
                </div>
            </div>
            <div class="idea-image-container">
                <img src="https://thumbs.dreamstime.com/b/house-garden-mosaic-decoration-made-colorful-plastic-bottle-cups-handmade-kids-craft-recycling-art-sunny-day-caps-178805975.jpg" 
                alt="Bottle Cap Art Mosaic" class="idea-image">
            </div>
            <p class="idea-description">Colorful wall art created from collected bottle caps. Arrange them in patterns to create stunning mosaics.</p>
            <div class="idea-actions">
                <button class="action-btn">Try This Idea</button>
                <span class="comments">52 Comments</span>
            </div>
        </div>
        <!-- END OF SAMPLE POSTS -->
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
                <div class="projects-scroll">
                    <?php if (count($projects) === 0): ?>
                        <p>No Projects for now</p>
                    <?php else: ?>
                        <?php foreach ($projects as $project): ?>
                            <a href="project_details.php?id=<?= $project['project_id'] ?>" class="project-item-link">
                                <div class="project-item">
                                    <strong><?= htmlspecialchars($project['project_name']) ?></strong>
                                    <div><?= htmlspecialchars($project['description']) ?></div>
                                    <div class="project-date"><?= htmlspecialchars(date('M d, Y', strtotime($project['created_at']))) ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
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

                <div class="leaderboard-scroll">
                    <?php if (count($leaders) === 0): ?>
                        <p>No users yet.</p>
                    <?php else: ?>
                        <?php foreach ($leaders as $i => $leader): ?>
                            <a href="profile_view.php?user_id=<?= (int)$leader['user_id'] ?>" class="leaderboard-item-link">
                                <div class="leaderboard-item">
                                    <div class="leaderboard-rank">#<?= $i + 1 ?></div>
                                    <div class="leaderboard-info">
                                        <strong class="leader-name"><?= htmlspecialchars($leader['first_name']) ?></strong>
                                        <div class="leader-points"><?= htmlspecialchars($leader['points']) ?> pts</div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
   
            <!-- Donation Popup Form -->
            <div id="donationPopup" class="popup-container" style="display:none;">
                <div class="popup-content">
                    <button class="close-btn">&times;</button>
                    <h2>Post Donation</h2>

                    <!-- ✅ NEW WRAPPER for scrollable content -->
                    <div id="donationFormContainer" class="popup-scroll-area">
                        <form id="donationForm" action="homepage.php" method="POST" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="wasteType">Type of Waste:</label>
                                <select id="wasteType" name="wasteType" required>
                                    <option value="">Select waste type</option>
                                    <option value="Plastic">Plastic</option>
                                    <option value="Paper">Paper</option>
                                    <option value="Metal">Metal</option>
                                    <option value="Glass">Glass</option>
                                    <option value="Electronic">Electronic</option>
                                    <option value="Other">Other</option> <!-- ✅ NEW -->
                                </select>
                            </div>

                            <!-- ✅ Hidden field that appears only if "Other" is selected -->
                            <div class="form-group" id="otherWasteGroup" style="display: none;">
                                <label for="otherWaste">Please specify:</label>
                                <input type="text" id="otherWaste" name="otherWaste" placeholder="Type custom waste category...">
                            </div>


                            <div class="form-group" id="subcategory-group" style="display:none;">
                                <label for="subcategory">Subcategory:</label>
                                <select id="subcategory" name="subcategory">
                                    <option value="">-- Select Subcategory --</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="quantity">Quantity:</label>
                                <input type="number" id="quantity" name="quantity" placeholder="Enter quantity" min="1" required>
                            </div>

                            <div class="form-group">
                                <label for="description">Description:</label>
                                <textarea id="description" name="description" placeholder="Describe your donation..." rows="4" required></textarea>
                            </div>

                            <div class="form-group">
                                <label for="photos">Attach Photos (up to 4):</label>
                                <div class="file-upload">
                                    <input type="file" id="photos" name="photos[]" accept="image/*" multiple>
                                    <label for="photos" class="file-upload-label">Choose Files</label>
                                    <span id="file-chosen">No files chosen</span>
                                </div>
                                <small class="form-hint">You can upload up to 4 photos. Only JPG, PNG, and GIF files are allowed.</small>

                                <div id="file-count-message" class="file-count-message" style="display: none;"></div>

                                <div id="photoPreviewContainer" class="photo-preview-container">
                                    <div id="photoPreview" class="photo-preview"></div>
                                </div>
                            </div>

                            <button type="submit" class="btn submit-btn">Post Donation</button>
                        </form>
                    </div>
                </div>
            </div>
            

<!-- ✅ Success Popup with Overlay -->
<div id="successPopup" class="popup-container" style="display: none;">
    <div class="popup-content success-popup">
        <div class="success-icon">
            <i class="fas fa-gift"></i>
        </div>
        <h2>Congratulations!</h2>
        <p>You’ve successfully donated your waste materials! 🎉<br>
            Please wait for others to claim your donation.
        </p>
        <button class="continue-btn" id="continueBtn">Continue</button>
    </div>
</div>


<!-- === Request Donation Popup (with Cancel at Bottom) === -->
<div id="requestPopup" class="popup-container" style="display:none;">
    <div class="popup-content">
        <h2 style="text-align:center; color:#2e7d32; font-weight:800; margin-bottom:15px;">
            Request Materials
        </h2>

        <form id="requestFormAjax" method="POST" action="homepage.php">
            <input type="hidden" id="popupDonationId" name="donation_id">

            <div class="form-group">
                <label>Waste:</label>
                <span id="popupWasteName" style="font-weight:500;"></span>
            </div>

            <div class="form-group">
                <label>Available Items:</label>
                <span id="popupAvailable" style="font-weight:500;"></span>
            </div>

            <div class="form-group">
                <label>Quantity to Claim:</label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <button type="button" onclick="updateQuantity(-1)"
                        style="width:32px;height:32px;border:none;background:#f0f0f0;border-radius:6px;cursor:pointer;font-size:16px;">−</button>
                    <input type="text" id="quantityClaim" name="quantity_claim" value="1"
                        readonly style="width:50px;text-align:center;border:1.5px solid #ccc;border-radius:6px;padding:6px;">
                    <button type="button" onclick="updateQuantity(1)"
                        style="width:32px;height:32px;border:none;background:#f0f0f0;border-radius:6px;cursor:pointer;font-size:16px;">+</button>
                </div>
            </div>

            <div class="form-group">
                <label>Recycling Project:</label>
                <select name="project_id" required>
                    <option value="">Select a project</option>
                    <?php foreach ($user_projects as $project): ?>
                        <option value="<?= $project['project_id'] ?>"><?= htmlspecialchars($project['project_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Urgency Level:</label>
                <select name="urgency_level" required>
                    <option value="High">High (Immediate Need)</option>
                    <option value="Medium">Medium (Within 2 weeks)</option>
                    <option value="Low">Low (Planning ahead)</option>
                </select>
            </div>

            <div class="popup-btn-group">
                <button type="submit" name="submit_request_donation" class="request-btn">Submit Request</button>
                <button type="button" class="cancel-btn" onclick="closeRequestPopup()">Cancel</button>
            </div>
        </form>
    </div>
</div>


<!-- Request Success Popup -->
<div id="requestSuccessPopup" class="popup-container" style="display:none;">
    <div class="popup-content success-popup">
        <h2>Request Sent!</h2>
        <p>Your request has been submitted successfully. Please wait for the donor’s response.</p>
        <button class="continue-btn" onclick="closeRequestSuccessPopup()">Continue</button>
    </div>
</div>



<!-- Photo Zoom Modal -->
<div id="photoZoomModal" class="photo-zoom-modal" style="display:none;">
    <span class="close-modal">&times;</span>
    <img id="zoomedPhoto" class="zoomed-photo" src="" alt="Zoomed Photo">
</div>

<!-- Include Settings Modal -->
    <?php include 'includes/settings_modal.php'; ?>
    

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

    function toggleComments(button, donationId) {
        const commentsSection = document.getElementById('comments-' + donationId);
        const isVisible = commentsSection.style.display === 'block';
        commentsSection.style.display = isVisible ? 'none' : 'block';
        
        button.innerHTML = isVisible ? 
            '<i class="fas fa-comment"></i> Comments' : 
            '<i class="fas fa-times"></i> Close Comments';
    }

    function openTab(tabName) {
        document.getElementById('donations').style.display = tabName === 'donations' ? 'block' : 'none';
        document.getElementById('recycled-ideas').style.display = tabName === 'recycled-ideas' ? 'block' : 'none';
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        if (tabName === 'donations') document.querySelectorAll('.tab-btn')[0].classList.add('active');
        else document.querySelectorAll('.tab-btn')[1].classList.add('active');
    }
    
    document.getElementById('userProfile').addEventListener('click', function() {
        this.classList.toggle('active');
    });
    document.addEventListener('click', function(event) {
        const userProfile = document.getElementById('userProfile');
        if (!userProfile.contains(event.target)) {
            userProfile.classList.remove('active');
        }
    });
    

document.addEventListener('DOMContentLoaded', function () {
    const donateWasteBtn = document.getElementById('donateWasteBtn');
    const donationPopup = document.getElementById('donationPopup');
    const donationFormContainer = document.getElementById('donationFormContainer');
    const successPopup = document.getElementById('successPopup');
    const urlParams = new URLSearchParams(window.location.search);
    

        // ✅ --- Show success popup if donation was successful ---
    if (urlParams.has('donation_success')) {
        // Prefer showing only the success popup — do NOT open the donation modal
        if (successPopup) {
            // Ensure donation popup & form are hidden
            if (donationPopup) donationPopup.style.display = 'none';
            if (donationFormContainer) donationFormContainer.style.display = 'none';

            // Show success popup overlay (use flex so it centers)
            successPopup.style.display = 'flex';
            // ensure it appears above everything
            successPopup.style.zIndex = '99999';

            // Add dim class to body (if your CSS uses it)
            document.body.classList.add('dimmed');
        } else {
            console.warn('Success popup element not found in DOM');
        }

        // Remove query from URL so popup doesn’t reappear on refresh
        try { window.history.replaceState({}, document.title, window.location.pathname); } catch(e) {}
    }


    // --- Subcategory dropdown logic ---
    const categorySelect = document.getElementById("wasteType");
    const subcategoryGroup = document.getElementById("subcategory-group");
    const subcategorySelect = document.getElementById("subcategory");

    const subcategories = {
        Plastic: ["Plastic Bottles", "Plastic Containers", "Plastic Bags", "Wrappers"],
        Paper: ["Newspapers", "Cardboard", "Magazines", "Office Paper"],
        Glass: ["Glass Bottles", "Glass Jars", "Broken Glassware"],
        Metal: ["Aluminum Cans", "Tin Cans", "Scrap Metal"],
        Electronic: ["Old Phones", "Chargers", "Batteries", "Broken Gadgets"]
    };

    // Handle "Other" input field visibility
    const otherWasteGroup = document.getElementById("otherWasteGroup");
    const otherWasteInput = document.getElementById("otherWaste");

    categorySelect.addEventListener("change", function() {
        const selected = this.value;

        // Handle subcategories
        subcategorySelect.innerHTML = "<option value=''>-- Select Subcategory --</option>";

        if (selected && subcategories[selected]) {
            subcategories[selected].forEach(function(item) {
                const option = document.createElement("option");
                option.value = item;
                option.textContent = item;
                subcategorySelect.appendChild(option);
            });
            subcategoryGroup.style.display = "block";
            subcategorySelect.setAttribute("required", "required");
        } else {
            subcategoryGroup.style.display = "none";
            subcategorySelect.removeAttribute("required");
        }

        // Show/Hide "Other" input field
        if (selected === "Other") {
            otherWasteGroup.style.display = "block";
            otherWasteInput.setAttribute("required", "required");
        } else {
            otherWasteGroup.style.display = "none";
            otherWasteInput.removeAttribute("required");
            otherWasteInput.value = "";
        }
    });

    // --- Request success / error handling ---
    if (urlParams.has('request_success')) {
        if (document.getElementById('requestSuccessPopup')) {
            document.getElementById('requestSuccessPopup').style.display = 'flex';
        } else {
            alert('Request submitted successfully.');
        }
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    if (urlParams.has('request_error')) {
        const e = urlParams.get('request_error');
        let msg = 'Failed to submit request.';
        if (e === 'self') msg = 'You cannot request your own donation.';
        if (e === 'over') msg = 'Requested quantity exceeds available items.';
        if (e === 'notfound') msg = 'Donation not found.';
        if (e === 'concurrent') msg = 'Unable to process request (item may have been claimed by someone else).';
        if (e === 'invalid') msg = 'Invalid request data.';
        alert(msg);
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // --- Open donation popup ---
    if (!donateWasteBtn || !donationPopup) {
        console.error('Required elements not found in the DOM');
        return;
    }

    donateWasteBtn.addEventListener('click', function (e) {
        e.preventDefault();
        donationPopup.style.display = 'flex';
        donationFormContainer.style.display = 'block';
        successPopup.style.display = 'none';
    });

    // Close popup when clicking outside
    donationPopup.addEventListener('click', function (e) {
        if (e.target === donationPopup) {
            donationPopup.style.display = 'none';
        }
    });

    // Close button
    document.querySelectorAll('.close-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            donationPopup.style.display = 'none';
        });
    });

    // --- Form validation ---
    document.getElementById('donationForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const wasteType = document.getElementById('wasteType').value;
        const quantity = document.getElementById('quantity').value;
        const description = document.getElementById('description').value;
        
        if (!wasteType || !quantity || !description) {
            alert('Please fill in all required fields');
            return;
        }
        
        this.submit();
    });

    // --- Continue button (after success popup) ---
    const continueBtn = document.getElementById('continueBtn');
    if (continueBtn) {
        continueBtn.addEventListener('click', function() {
            donationPopup.style.display = 'none';
            document.getElementById('donationForm').reset();
            document.getElementById('file-chosen').textContent = 'No files chosen';
            document.getElementById('photoPreview').innerHTML = '';
            document.getElementById('file-count-message').style.display = 'none';
            selectedFiles = [];
            donationFormContainer.style.display = 'block';
            successPopup.style.display = 'none';
        });
    }

    // --- File upload preview handling ---
    let selectedFiles = [];
    const photoInput = document.getElementById('photos');
    const maxFiles = 4;
    
    photoInput.addEventListener('change', function() {
        const newFiles = Array.from(this.files);
        
        newFiles.forEach(file => {
            if (selectedFiles.length < maxFiles) {
                const isDuplicate = selectedFiles.some(
                    selectedFile => selectedFile.name === file.name && selectedFile.size === file.size
                );
                
                if (!isDuplicate) {
                    selectedFiles.push(file);
                }
            }
        });
        
        const dataTransfer = new DataTransfer();
        selectedFiles.forEach(file => dataTransfer.items.add(file));
        photoInput.files = dataTransfer.files;
        
        updateFileDisplay();
    });

    function updateFileDisplay() {
        const fileNames = selectedFiles.length > 0 
            ? selectedFiles.map(f => f.name).join(', ') 
            : 'No files chosen';
            
        document.getElementById('file-chosen').textContent = fileNames;
        
        const photoPreview = document.getElementById('photoPreview');
        photoPreview.innerHTML = '';
        
        if (selectedFiles.length > 0) {
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
                        
                        img.addEventListener('mouseover', function() {
                            this.style.opacity = '0.8';
                        });
                        img.addEventListener('mouseout', function() {
                            this.style.opacity = '1';
                        });
                        
                        img.addEventListener('click', function () {
                            openPhotoZoom(e.target.result);
                        });
                        
                        const removeBtn = document.createElement('button');
                        removeBtn.className = 'remove-image-btn';
                        removeBtn.innerHTML = '&times;';
                        removeBtn.title = 'Remove image';
                        
                        removeBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            selectedFiles.splice(index, 1);
                            
                            const dataTransfer = new DataTransfer();
                            selectedFiles.forEach(file => dataTransfer.items.add(file));
                            photoInput.files = dataTransfer.files;
                            
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
        
        const fileCountElement = document.getElementById('file-count-message');
        if (selectedFiles.length > 0) {
            fileCountElement.textContent = `Selected ${selectedFiles.length} of ${maxFiles} files`;
            fileCountElement.style.display = 'block';
        } else {
            fileCountElement.style.display = 'none';
        }
        
        if (selectedFiles.length > maxFiles) {
            const warning = document.createElement('p');
            warning.textContent = `Maximum ${maxFiles} files allowed. Only the first ${maxFiles} will be uploaded.`;
            warning.style.color = 'red';
            warning.style.fontSize = '12px';
            warning.style.marginTop = '10px';
            warning.style.width = '100%';
            photoPreview.appendChild(warning);
            
            selectedFiles = selectedFiles.slice(0, maxFiles);
            
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            photoInput.files = dataTransfer.files;
            
            updateFileDisplay();
        }
    }
});

    

    let currentAvailable = 0;

function openRequestPopup(donationId, wasteName, available) {
    document.getElementById('popupDonationId').value = donationId;
    document.getElementById('popupWasteName').textContent = wasteName;
    document.getElementById('popupAvailable').textContent = available;
    document.getElementById('quantityClaim').value = 1;
    currentAvailable = available;

    document.getElementById('requestPopup').style.display = 'flex';
}

function closeRequestPopup() {
    document.getElementById('requestPopup').style.display = 'none';
}



function updateQuantity(change) {
    let qtyInput = document.getElementById('quantityClaim');
    let qty = parseInt(qtyInput.value) || 1;
    qty += change;
    if (qty < 1) qty = 1;
    if (qty > currentAvailable) qty = currentAvailable;
    qtyInput.value = qty;
}

       function showRequestSuccessPopup() {
        document.getElementById('requestPopup').style.display = 'none';
        document.getElementById('requestSuccessPopup').style.display = 'flex';
    }

        function closeRequestSuccessPopup() {
        document.getElementById('requestSuccessPopup').style.display = 'none';
    }


    function openPhotoZoom(photoSrc) {
        const modal = document.getElementById('photoZoomModal');
        const zoomedPhoto = document.getElementById('zoomedPhoto');
        zoomedPhoto.src = photoSrc;
        modal.style.display = 'flex';
    }

    document.querySelector('.close-modal').addEventListener('click', function () {
        document.getElementById('photoZoomModal').style.display = 'none';
    });



    //Feedback Modal
    document.addEventListener("DOMContentLoaded", function () {
    const feedbackBtn = document.getElementById("feedbackBtn");
    const feedbackModal = document.getElementById("feedbackModal");
    const feedbackCloseBtn = document.getElementById("feedbackCloseBtn");
    const emojiOptions = document.querySelectorAll(".emoji-option");
    const feedbackSubmitBtn = document.getElementById("feedbackSubmitBtn");
    const feedbackText = document.getElementById("feedbackText");
    const ratingError = document.getElementById("ratingError");
    const textError = document.getElementById("textError");
    const thankYouMessage = document.getElementById("thankYouMessage");
    const feedbackForm = document.getElementById("feedbackForm");
    const spinner = document.getElementById("spinner");

    let selectedRating = 0;

    // Open modal
    feedbackBtn.addEventListener("click", () => {
        feedbackModal.style.display = "flex";
        feedbackForm.style.display = "block";
        thankYouMessage.style.display = "none";
    });

    // Close modal
    feedbackCloseBtn.addEventListener("click", () => {
        feedbackModal.style.display = "none";
    });

    // Close modal when clicking outside
    window.addEventListener("click", (e) => {
        if (e.target === feedbackModal) {
            feedbackModal.style.display = "none";
        }
    });

    // Rating selection
    emojiOptions.forEach(option => {
        option.addEventListener("click", () => {
            emojiOptions.forEach(o => o.classList.remove("selected"));
            option.classList.add("selected");
            selectedRating = option.getAttribute("data-rating");
            ratingError.style.display = "none";
        });
    });

    // Submit feedback
    feedbackSubmitBtn.addEventListener("click", function (e) {
        e.preventDefault();

        let valid = true;

        if (selectedRating === 0) {
            ratingError.style.display = "block";
            valid = false;
        }

        if (feedbackText.value.trim() === "") {
            textError.style.display = "block";
            valid = false;
        } else {
            textError.style.display = "none";
        }

        if (!valid) return;

        // Show spinner
        spinner.style.display = "inline-block";
        feedbackSubmitBtn.disabled = true;

        // Send feedback to server (AJAX)
        fetch("feedback_process.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            credentials: "same-origin",
            body: `rating=${selectedRating}&feedback=${encodeURIComponent(feedbackText.value)}`
        })
        .then(res => res.json())
        .then(data => {
            spinner.style.display = "none";
            feedbackSubmitBtn.disabled = false;

            if (data.status === "success") {
                feedbackForm.style.display = "none";
                thankYouMessage.style.display = "block";

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

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Utilities
    const qs = sel => document.querySelector(sel);
    const qsa = sel => Array.from(document.querySelectorAll(sel));
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe.replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }

    // Toggle comments panels
    window.toggleComments = (button, donationId) => {
        const commentsSection = document.getElementById('comments-' + donationId);
        if (!commentsSection) return;
        const isVisible = commentsSection.style.display === 'block';
        commentsSection.style.display = isVisible ? 'none' : 'block';
        button.innerHTML = isVisible ? '<i class="fas fa-comment"></i> Comments' : '<i class="fas fa-times"></i> Close Comments';
    };

    // ---- Time display (Accurate + Auto-update) ----
    function formatTimeDifferenceFromISO(isoString) {
        if (!isoString) return '';
        const commentDate = new Date(isoString);
        if (isNaN(commentDate)) return '';

        const now = new Date();
        const diffSec = Math.floor((now - commentDate) / 1000);
        if (diffSec < 10) return 'Just now';
        if (diffSec < 60) return `${diffSec}s ago`;
        if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
        if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
        if (diffSec < 7 * 86400) return `${Math.floor(diffSec / 86400)}d ago`;

        // Older → show full readable Manila local time
        return commentDate.toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });
    }

    function refreshAllCommentTimes() {
        document.querySelectorAll('.comment-time').forEach(el => {
            const iso = el.dataset.time;
            if (!iso) return;
            el.textContent = formatTimeDifferenceFromISO(iso);
        });
    }
    refreshAllCommentTimes();
    setInterval(refreshAllCommentTimes, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshAllCommentTimes(); });

    // ---- Reply / Edit / Delete handlers ----
    document.addEventListener('click', async (e) => {
        // find comment item
        const item = e.target.closest('.comment-item');
        // REPLY
        if (e.target.classList.contains('reply-btn')) {
            e.preventDefault();
            // remove existing reply box
            const existing = document.querySelector('.reply-box');
            if (existing) existing.remove();

            const replyForm = document.createElement('form');
            replyForm.className = 'reply-box';
            replyForm.innerHTML = `
                <textarea name="reply" class="comment-input" placeholder="Write a reply..." required style="width:100%;min-height:60px;margin:8px 0;"></textarea>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="submit" class="comment-submit">Reply</button>
                    <button type="button" class="reply-cancel">Cancel</button>
                </div>
            `;

            // insert after the comment item (so it's visually below)
            item.insertAdjacentElement('afterend', replyForm);
            replyForm.reply.focus();

            replyForm.querySelector('.reply-cancel').addEventListener('click', () => replyForm.remove());

            replyForm.addEventListener('submit', async (ev) => {
                ev.preventDefault();
                const text = replyForm.reply.value.trim();
                if (!text) return;

                const donationId = item.dataset.donationId;
                const parentId = item.dataset.id;

                // send to homepage.php via AJAX
                try {
                    const payload = new URLSearchParams();
                    payload.append('donation_id', donationId);
                    payload.append('comment_text', text);
                    payload.append('parent_id', parentId);

                    const res = await fetch('homepage.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: payload.toString()
                    });
                    const data = await res.json();
                    if (data.success) {
                        // Build new reply element
                        const li = document.createElement('li');
                        li.className = 'comment-item';
                        li.dataset.id = data.comment_id;
                        li.dataset.donationId = donationId;

                        const nowIso = data.created_iso || new Date().toISOString();

                        li.innerHTML = `
                            <div class="comment-avatar">${data.user_initial}</div>
                            <div class="comment-content">
                                <div class="comment-author">${data.user_name}</div>
                                <div class="comment-text">${data.comment_text}</div>
                                <div class="comment-time" data-time="${nowIso}">Just now</div>
                                <div class="comment-actions">
                                    <button class="reply-btn">Reply</button>
                                    <button class="edit-btn">Edit</button>
                                    <button class="delete-btn">Delete</button>
                                </div>
                            </div>
                        `;

                        // find or create reply-list under the parent item
                        let replyList = item.querySelector('.reply-list');
                        if (!replyList) {
                            replyList = document.createElement('ul');
                            replyList.className = 'reply-list';
                            item.appendChild(replyList);
                        }
                        replyList.appendChild(li);
                        replyForm.remove();
                        refreshAllCommentTimes();
                    } else {
                        alert(data.message || 'Failed to post reply.');
                    }
                } catch (err) {
                    console.error(err);
                    alert('Error posting reply.');
                }
            });
            return;
        }

        // EDIT
        if (e.target.classList.contains('edit-btn')) {
            e.preventDefault();
            const contentEl = item.querySelector('.comment-text');
            const btn = e.target;
            const originalText = contentEl.innerHTML;

            // make editable
            const textarea = document.createElement('textarea');
            textarea.className = 'edit-textarea';
            textarea.value = contentEl.textContent.trim();
            textarea.style.width = '100%';
            textarea.style.minHeight = '60px';

            contentEl.replaceWith(textarea);
            btn.textContent = 'Save';

            const saveHandler = async () => {
                const newText = textarea.value.trim();
                if (!newText) { alert('Empty comment.'); return; }

                const payload = new FormData();
                payload.append('id', item.dataset.id);
                payload.append('content', newText);

                try {
                    const res = await fetch('edit_comment.php', { method: 'POST', body: payload });
                    const result = await res.json();
                    if (result.success) {
                        const newDiv = document.createElement('div');
                        newDiv.className = 'comment-text';
                        newDiv.innerHTML = escapeHtml(newText).replace(/\n/g, '<br>');
                        textarea.replaceWith(newDiv);
                        btn.textContent = 'Edit';
                    } else {
                        alert(result.message || 'Edit failed.');
                        textarea.replaceWith((() => {
                            const orig = document.createElement('div');
                            orig.className = 'comment-text';
                            orig.innerHTML = originalText;
                            return orig;
                        })());
                        btn.textContent = 'Edit';
                    }
                } catch (err) {
                    console.error(err);
                    alert('Network error editing comment.');
                }
                btn.removeEventListener('click', saveHandler);
            };

            btn.addEventListener('click', saveHandler, { once: true });
            return;
        }

        // DELETE
        if (e.target.classList.contains('delete-btn')) {
            e.preventDefault();
            if (!confirm('Delete this comment (and its replies)?')) return;

            const payload = new FormData();
            payload.append('id', item.dataset.id);

            try {
                const res = await fetch('delete_comment.php', { method: 'POST', body: payload });
                const result = await res.json();
                if (result.success) {
                    item.style.transition = 'opacity .25s';
                    item.style.opacity = '0';
                    setTimeout(() => item.remove(), 250);
                } else {
                    alert(result.message || 'Delete failed.');
                }
            } catch (err) {
                console.error(err);
                alert('Network error while deleting.');
            }
            return;
        }
    });

    // ---- Inline posting from top-level forms (non-AJAX fallback) ----
    // If your page has forms that POST normally, keep that behavior.
    // Optionally you can intercept .post-comment-btn to AJAX post too.

    // Optional: intercept top-level comment forms to post via AJAX for immediate UI update
    document.querySelectorAll('form[action="homepage.php"]').forEach(form => {
        // only handle those with a textarea named comment_text
        const txt = form.querySelector('textarea[name="comment_text"]');
        if (!txt) return;
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const donationId = form.querySelector('input[name="donation_id"]').value;
            const text = txt.value.trim();
            if (!text) return;
            try {
                const payload = new URLSearchParams();
                payload.append('donation_id', donationId);
                payload.append('comment_text', text);
                // no parent_id for top-level

                const res = await fetch('homepage.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload.toString()
                });
                const data = await res.json();
                if (data.success) {
                    // prepend new top-level comment to the .comments-list inside the donation's section
                    const list = form.closest('.comments-section').querySelector('.comments-list');
                    const li = document.createElement('li');
                    li.className = 'comment-item';
                    li.dataset.id = data.comment_id;
                    li.dataset.donationId = donationId;
                    const nowIso = data.created_iso || new Date().toISOString();
                    li.innerHTML = `
                        <div class="comment-avatar">${data.user_initial}</div>
                        <div class="comment-content">
                            <div class="comment-author">${data.user_name}</div>
                            <div class="comment-text">${data.comment_text}</div>
                            <div class="comment-time" data-time="${nowIso}">Just now</div>
                            <div class="comment-actions">
                                <button class="reply-btn">Reply</button>
                                <button class="edit-btn">Edit</button>
                                <button class="delete-btn">Delete</button>
                            </div>
                        </div>
                    `;
                    // If there was a "no-comments" element, remove it
                    const noComments = list.querySelector('.no-comments');
                    if (noComments) noComments.remove();

                    // insert at top
                    list.insertBefore(li, list.firstChild);
                    txt.value = '';
                    refreshAllCommentTimes();
                } else {
                    alert(data.message || 'Failed to post comment.');
                }
            } catch (err) {
                console.error(err);
                alert('Failed to post comment.');
            }
        });
    });

    
}); // DOMContentLoaded

function toggleReplyForm(commentId) {
    const form = document.getElementById(`reply-form-${commentId}`);
    if (!form) return;
    form.style.display = form.style.display === 'none' || form.style.display === '' ? 'block' : 'none';
}

// === Restrict Quantity Input to Digits Only ===
document.addEventListener("DOMContentLoaded", function() {
  const quantityInput = document.getElementById("quantity");
  if (quantityInput) {
    // Block invalid keys (e, E, +, -, .)
    quantityInput.addEventListener("keydown", function(e) {
      if (
        e.key === "e" || e.key === "E" ||
        e.key === "+" || e.key === "-" ||
        e.key === "."
      ) {
        e.preventDefault();
      }
    });

    // Clean up pasted values (remove non-numeric)
    quantityInput.addEventListener("input", function(e) {
      this.value = this.value.replace(/[^0-9]/g, '');
    });
  }
});

</script>

</body>
</html>