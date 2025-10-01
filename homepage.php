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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_text']) && isset($_POST['donation_id']) && !isset($_POST['submit_request_donation'])) {
    // note: the extra check !isset($_POST['submit_request_donation']) avoids collision with request form inputs
    $comment_text = htmlspecialchars($_POST['comment_text']);
    $donation_id = (int)$_POST['donation_id'];
    $user_id = $_SESSION['user_id'];
    $created_at = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("INSERT INTO comments (donation_id, user_id, comment_text, created_at) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $donation_id, $user_id, $comment_text, $created_at);
    
    if ($stmt->execute()) {
        // redirect back to avoid resubmission (no donation_success param for comments)
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } else {
        die('Error: Failed to post comment. ' . $stmt->error);
    }
}

/* ----------------------------
   Handle donation form submission
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wasteType']) && !isset($_POST['submit_request_donation'])) {
    if (empty($_POST['wasteType']) || empty($_POST['quantity']) || empty($_POST['description'])) {
        die('Error: All fields are required.');
    }
    
    $item_name = htmlspecialchars($_POST['wasteType']);
    $quantity = (int) $_POST['quantity'];
    $category = htmlspecialchars($_POST['wasteType']);
    $description = htmlspecialchars($_POST['description']);
    $donor_id = $_SESSION['user_id'];
    $donated_at = date('Y-m-d H:i:s');
    $image_paths_json = null;

    // Handle photo upload (unchanged)
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
                if (!in_array($file_type, $allowed_types)) {
                    die('Error: Only JPG, PNG, and GIF files are allowed.');
                }
                $unique_file_name = uniqid() . '_' . basename($file_name);
                $target_file = $upload_dir . $unique_file_name;
                if (move_uploaded_file($file_tmp, $target_file)) {
                    $image_paths[] = $target_file;
                    $file_count++;
                } else {
                    die('Failed to upload image: ' . $file_name);
                }
            }
        }
        if (!empty($image_paths)) {
            $image_paths_json = json_encode($image_paths);
        }
    }

    // Insert donation into donations table
    $total_quantity = $quantity; // store the original amount

    $stmt = $conn->prepare("INSERT INTO donations (item_name, quantity, total_quantity, category, description, donor_id, donated_at, image_path, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Available')");
    if (!$stmt) {
        die('Error: Failed to prepare statement. ' . $conn->error);
    }

    if (!$stmt->bind_param("sissssss", $item_name, $quantity, $total_quantity, $category, $description, $donor_id, $donated_at, $image_paths_json)) {
        die('Error: Failed to bind parameters. ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        die('Error: Failed to execute statement. ' . $stmt->error);
    }

    // Update USER STATS (only counts)
    $stats_check = $conn->query("SELECT * FROM user_stats WHERE user_id = $donor_id");
    if ($stats_check->num_rows === 0) {
        $conn->query("INSERT INTO user_stats (user_id, items_donated, items_recycled, projects_completed, achievements_earned, badges_earned) 
                      VALUES ($donor_id, $quantity, 0, 0, 0, 0)");
    } else {
        $conn->query("UPDATE user_stats SET items_donated = items_donated + $quantity WHERE user_id = $donor_id");
    }

    // redirect with donation success flag so frontend can show popup
    header('Location: ' . $_SERVER['PHP_SELF'] . '?donation_success=1');
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

    // Basic validation
    if ($donation_id <= 0 || $quantity_claim <= 0) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?request_error=invalid');
        exit();
    }

    // Fetch donation row
    $chk = $conn->prepare("SELECT donor_id, quantity FROM donations WHERE donation_id = ?");
    $chk->bind_param("i", $donation_id);
    $chk->execute();
    $don = $chk->get_result()->fetch_assoc();

    if (!$don) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?request_error=notfound');
        exit();
    }

    // Block requesting own donation
    if ($don['donor_id'] == $user_id) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?request_error=self');
        exit();
    }

    // Check available quantity
    $available = (int)$don['quantity'];
    if ($quantity_claim > $available) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?request_error=over');
        exit();
    }

    // Atomically decrement donation quantity
    $upd = $conn->prepare("UPDATE donations SET quantity = quantity - ? WHERE donation_id = ? AND quantity >= ?");
    $upd->bind_param("iii", $quantity_claim, $donation_id, $quantity_claim);
    $upd->execute();

    if ($upd->affected_rows === 0) {
        // update didn't apply (race condition or insufficient quantity)
        header('Location: ' . $_SERVER['PHP_SELF'] . '?request_error=concurrent');
        exit();
    }

    // If quantity hit zero, mark Completed
    $conn->query("UPDATE donations SET status = 'Completed' WHERE donation_id = $donation_id AND quantity = 0");

    // Ensure donation_requests table exists (create if missing)
    $create_sql = "CREATE TABLE IF NOT EXISTS donation_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        donation_id INT NOT NULL,
        user_id INT NOT NULL,
        quantity_claim INT NOT NULL,
        project_id INT NULL,
        urgency_level VARCHAR(20) DEFAULT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (donation_id),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $conn->query($create_sql);

    // Insert request record
    $ins = $conn->prepare("INSERT INTO donation_requests (donation_id, user_id, quantity_claim, project_id, urgency_level, requested_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $ins->bind_param("iiiis", $donation_id, $user_id, $quantity_claim, $project_id, $urgency_level);
    $ins->execute();

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
$result = $conn->query("SELECT * FROM projects WHERE user_id = {$user['user_id']} ORDER BY created_at DESC LIMIT 3");
while ($row = $result->fetch_assoc()) $projects[] = $row;

// Fetch leaderboard
$leaders = [];
$result = $conn->query("SELECT first_name, points FROM users ORDER BY points DESC LIMIT 10");
while ($row = $result->fetch_assoc()) $leaders[] = $row;

/* Helper to render nested comments remains the same if you have it earlier.
   (If you removed it earlier, re-add the render_comments function.) */
function render_comments($comments, $donation_id, $parent_id = NULL) {
    foreach ($comments as $comment) {
        // if parent_id column exists you can use nested comments; for now this assumes flat list
        if ($comment['parent_id'] == $parent_id) {
            echo "<div class='comment'>";
            echo "<div class='comment-header'>
                    <span class='comment-author'>" . htmlspecialchars($comment['first_name']) . "</span>
                    <span class='comment-time'>" . getTimeAgo($comment['created_at']) . "</span>
                  </div>";
            echo "<p class='comment-text'>" . nl2br(htmlspecialchars($comment['comment_text'])) . "</p>";
            // reply form (if you added parent_id in DB)
            echo "<div class='add-comment' style='margin-left:20px;'>
                    <form method='POST' action='homepage.php'>
                        <textarea name='comment_text' class='comment-textarea' placeholder='Reply...' required></textarea>
                        <input type='hidden' name='donation_id' value='{$donation_id}'>
                        <input type='hidden' name='parent_id' value='{$comment['comment_id']}'>
                        <button type='submit' class='post-comment-btn'>Reply</button>
                    </form>
                  </div>";
            // Recursive render if nested
            render_comments($comments, $donation_id, $comment['comment_id']);
            echo "</div>";
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
                    <li><a href="homepage.php" class="active"><i class="fas fa-home"></i>Home</a></li>
                    <li><a href="browse.php" ><i class="fas fa-search"></i>Browse</a></li>
                    <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
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
                <span class="category">Category: <?= htmlspecialchars($donation['category']) ?></span>
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
<!-- Donation Popup Form -->
<div id="donationPopup" class="popup-container">
    <div class="popup-content" id="donationFormContainer">
        <div class=popup-header>
            <h2>Post Donation</h2>
            <button class="close-btn">&times;</button>
        </div>

        <form id="donationForm" action="homepage.php" method="POST" enctype="multipart/form-data">
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

            <button type="submit" class="submit-btn">Post Donation</button>
        </form>
    </div>
</div>


     <div class="popup-content success-popup" id="successPopup" style="display: none;">
        <h2>Congratulations!</h2>
        <p>You have<br>now donated your waste. Wait<br>for others to claim yours.</p>
        <button class="continue-btn" id="continueBtn">Continue</button>
        <button class="close-btn">&times;</button>
    </div>
</div>

<!-- Request Donation Popup -->
<div id="requestPopup" class="popup-container" style="display:none;">
    <div class="popup-content">
        <h2>Request Materials</h2>
        <form method="POST" action="homepage.php">
            <input type="hidden" id="popupDonationId" name="donation_id">

            <div class="form-group">
                <label>Waste:</label>
                <span id="popupWasteName"></span>
            </div>

            <div class="form-group">
                <label>Available Items:</label>
                <span id="popupAvailable"></span>
            </div>

            <div class="form-group">
                <label>Quantity to Claim:</label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <button type="button" onclick="updateQuantity(-1)">-</button>
                    <input type="text" id="quantityClaim" name="quantity_claim" value="1" readonly style="width:50px;text-align:center;">
                    <button type="button" onclick="updateQuantity(1)">+</button>
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

            <div style="margin-top:15px;">
                <button type="submit" name="submit_request_donation" class="request-btn">Submit Request</button>
                <button type="button" class="close-btn" onclick="closeRequestPopup()">Cancel</button>
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

        if (urlParams.has('request_success')) {
        // show your request success popup if available
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

        // donation_success handling (your existing logic)
    if (urlParams.has('donation_success')) {
        const donationPopup = document.getElementById('donationPopup');
        const donationFormContainer = document.getElementById('donationFormContainer');
        const successPopup = document.getElementById('successPopup');
        if (donationPopup && donationFormContainer && successPopup) {
            donationPopup.style.display = 'flex';
            donationFormContainer.style.display = 'none';
            successPopup.style.display = 'flex';
        } else {
            alert('Donation posted successfully.');
        }
        window.history.replaceState({}, document.title, window.location.pathname);
    }  

        

        if (!donateWasteBtn || !donationPopup) {
            console.error('Required elements not found in the DOM');
            return;
        }

        donateWasteBtn.addEventListener('click', function (e) {
            e.preventDefault();
            console.log('Donate Waste button clicked');
            donationPopup.style.display = 'flex';
            donationFormContainer.style.display = 'block';
            successPopup.style.display = 'none';
        });

        function closeDonationPopup() {
            donationPopup.classList.add('closing');
            setTimeout(() => {
                donationPopup.style.display = 'none';
                donationPopup.classList.remove('closing');
            }, 300); // match animation duration
        }

        donationPopup.addEventListener('click', function (e) {
            if (e.target === donationPopup) {
                closeDonationPopup();
            }
        });

        document.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                closeDonationPopup();
            });
        });


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
    const donationPopup = document.getElementById('donationPopup');

    zoomedPhoto.src = photoSrc;
    modal.style.display = 'flex';

    // Blur the donation popup
    donationPopup.classList.add('blurred');
    }

    // Close when clicking X
    document.querySelector('.close-modal').addEventListener('click', function () {
        closePhotoZoom();
    });

    // Close when clicking outside
    document.getElementById('photoZoomModal').addEventListener('click', function (e) {
        if (e.target === this) {
            closePhotoZoom();
        }
    });

    // Close with ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closePhotoZoom();
        }
    });

    function closePhotoZoom() {
        const modal = document.getElementById('photoZoomModal');
        const donationPopup = document.getElementById('donationPopup');
        modal.style.display = 'none';

        // Remove blur
        donationPopup.classList.remove('blurred');
    }


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
</body>
</html>