<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get the user ID from the URL parameter
$viewed_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($viewed_user_id === 0) {
    header('Location: homepage.php');
    exit();
}

// Fetch the viewed user's data
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $viewed_user_id);
$stmt->execute();
$viewed_user = $stmt->get_result()->fetch_assoc();

if (!$viewed_user) {
    header('Location: homepage.php');
    exit();
}

// Fetch current user data
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();

// Fetch viewed user's stats - use null coalescing to avoid errors
$viewed_user_stats = $conn->query("SELECT * FROM user_stats WHERE user_id = $viewed_user_id")->fetch_assoc() ?? [];

// Fetch viewed user's donations
$viewed_user_donations = [];
$result = $conn->query("SELECT * FROM donations WHERE donor_id = $viewed_user_id ORDER BY donated_at DESC LIMIT 10");
while ($row = $result->fetch_assoc()) $viewed_user_donations[] = $row;

// Fetch viewed user's projects
$viewed_user_projects = [];
$result = $conn->query("SELECT * FROM projects WHERE user_id = $viewed_user_id ORDER BY created_at DESC LIMIT 5");
while ($row = $result->fetch_assoc()) $viewed_user_projects[] = $row;

// Function to calculate time ago
function getTimeAgo($timestamp) {
    $currentTime = time();
    $timeDiff = $currentTime - strtotime($timestamp);
    
    if ($timeDiff < 60) {
        return 'just now';
    } elseif ($timeDiff < 3600) {
        $minutes = floor($timeDiff / 60);
        return $minutes . ' min ago';
    } elseif ($timeDiff < 86400) {
        $hours = floor($timeDiff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($timeDiff < 2592000) {
        $days = floor($timeDiff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', strtotime($timestamp));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(($viewed_user['first_name'] ?? 'User') . ' ' . ($viewed_user['last_name'] ?? '')) ?> | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/profile_view.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>

        
        .profile-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: #3d6a06;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 40px;
            margin-right: 25px;
            flex-shrink: 0;
        }
        
        .profile-header-content {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .profile-info {
            flex: 1;
        }
        
        .profile-fullname {
            font-size: 28px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        
        .profile-username {
            font-size: 18px;
            color: #2e8b57;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .profile-address {
            color: #666;
            font-size: 16px;
            margin-bottom: 15px;
        }
        
        .profile-bio {
            color: #666;
            font-style: italic;
            margin-top: 10px;
        }
        
        .profile-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 200px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2e8b57;
            display: block;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
        }
        
        .badges-section {
            margin-bottom: 30px;
        }
        
        .badges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .badge-item {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .badge-icon {
            font-size: 24px;
            margin-bottom: 8px;
            color: #2e8b57;
        }
        
        .badge-name {
            font-size: 12px;
            color: #666;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #2e8b57;
        }
        
        .donation-post {
            padding: 20px;
            border: 1px solid #e8e8e8;
            border-radius: 12px;
            margin-bottom: 20px;
            background-color: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .no-content {
            text-align: center;
            padding: 30px;
            color: #999;
            font-style: italic;
        }
        
        .combined-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }

        .donation-images {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin: 15px 0;
}

.donation-image {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 6px;
    cursor: pointer;
    transition: transform 0.2s;
}

.donation-image:hover {
    transform: scale(1.05);
}

/* Photo Zoom Modal */
.photo-zoom-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.8);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.zoomed-photo {
    max-width: 90%;
    max-height: 90%;
    border-radius: 10px;
}

.close-modal {
    position: absolute;
    top: 20px;
    right: 30px;
    font-size: 2rem;
    color: white;
    cursor: pointer;
}
        
        @media (min-width: 992px) {
            .combined-layout {
                grid-template-columns: 2fr 1fr;
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
                <?= strtoupper(substr(htmlspecialchars($current_user['first_name'] ?? 'U'), 0, 1)) ?>
            </div>
            <span class="profile-name"><?= htmlspecialchars($current_user['first_name'] ?? 'User') ?></span>
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

        <!-- Include Settings Modal -->
    <?php include 'includes/settings_modal.php'; ?>

            <div class="combined-layout">
                <div class="main-content-column">
                    <!-- User Profile Header -->
                    <div class="user-profile-card">
                        <div class="profile-header-content">
                            <div class="profile-avatar-large">
                                <?= strtoupper(substr(htmlspecialchars($viewed_user['first_name'] ?? 'U'), 0, 1)) ?>
                            </div>
                            <div class="profile-info">
                                <h1 class="profile-fullname"><?= htmlspecialchars(($viewed_user['first_name'] ?? 'User') . ' ' . ($viewed_user['last_name'] ?? '')) ?></h1>
                                <div class="profile-username">@<?= htmlspecialchars($viewed_user['first_name'] ?? 'User') ?></div>
                                <div class="profile-address">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?= htmlspecialchars($viewed_user['address'] ?? 'Address not provided') ?>
                                </div>
                                <!-- Replaced bio with Member Since -->
                                <div class="member-since-profile">
                                    <i class="fas fa-calendar-alt"></i> 
                                    Member since 
                                    <?php 
                                        if (!empty($viewed_user['created_at'])) {
                                            echo htmlspecialchars(date('F Y', strtotime($viewed_user['created_at'])));
                                        } else {
                                            echo 'Unknown date';
                                        }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- Profile Stats -->
                        <div class="section-title">Profile Stats</div>
                        <div class="profile-stats-grid">
                            <div class="stat-card">
                                <span class="stat-value"><?= htmlspecialchars($viewed_user_stats['items_recycled'] ?? 0) ?></span>
                                <span class="stat-label">Items Recycled</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-value"><?= htmlspecialchars($viewed_user_stats['items_donated'] ?? 0) ?></span>
                                <span class="stat-label">Items Donated</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-value"><?= htmlspecialchars($viewed_user['points'] ?? 0) ?></span>
                                <span class="stat-label">Total Points</span>
                            </div>
                        </div>

                        <!-- Eco Badges -->
                        <div class="section-title">Eco Badges</div>
                        <div class="badges-grid">
                        <?php
                        // Fetch badges earned by the viewed user with badge info
                        $stmt = $conn->prepare("
                            SELECT b.badge_name, b.description, b.icon 
                            FROM user_badges ub
                            JOIN badges b ON ub.badge_id = b.badge_id
                            WHERE ub.user_id = ?
                            ORDER BY b.badge_id ASC
                        ");
                        $stmt->bind_param("i", $viewed_user_id);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        // Check if the user has earned any badges
                        if ($result->num_rows === 0):
                        ?>
                            <div class="no-content">No badges earned yet.</div>
                        <?php
                        else:
                            while ($badge = $result->fetch_assoc()):
                        ?>
                            <div class="badge-item earned">
                                <div class="badge-icon">
                                    <i class="<?= htmlspecialchars($badge['icon']); ?>" style="color:gold;"></i>
                                </div>
                                <div class="badge-name"><?= htmlspecialchars($badge['badge_name']); ?></div>
                                <div class="badge-description" style="font-size:11px; color:#555;"><?= htmlspecialchars($badge['description']); ?></div>
                            </div>
                        <?php
                            endwhile;
                        endif;
                        $stmt->close();
                        ?>
                        </div>


                        <!-- Recent Donations -->
                        <div class="section-title">Recent Donations</div>
                        <?php if (count($viewed_user_donations) === 0): ?>
                            <div class="no-content">No donations yet.</div>
                        <?php else: ?>
                            <?php foreach ($viewed_user_donations as $donation): ?>
                            <div class="donation-post">
                                <div class="donation-user-header">
                                    <div class="user-avatar">
                                        <?= strtoupper(substr(htmlspecialchars($viewed_user['first_name'] ?? 'U'), 0, 1)) ?>
                                    </div>
                                    <div class="user-info">
                                        <div class="user-name">
                                            <a href="profile_view.php?user_id=<?= $viewed_user['user_id'] ?>" class="profile-link">
                                                <?= htmlspecialchars($viewed_user['first_name'] ?? 'User') ?>
                                            </a>
                                        </div>
                                        <div class="donation-meta">
                                            <span class="category">Category: <?= htmlspecialchars($donation['category'] ?? 'Unknown') ?></span>
                                            <span class="time-ago">
                                                <?php 
                                                    if (!empty($donation['donated_at'])) {
                                                        echo getTimeAgo($donation['donated_at']);
                                                    } else {
                                                        echo 'Unknown time';
                                                    }
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="quantity-info">
                                    <div class="quantity-label">Quantity: <?= htmlspecialchars($donation['quantity'] ?? 0) ?> units</div>
                                </div>
                                
                                <?php if (!empty($donation['description'])): ?>
                                <div class="donation-description">
                                    <p><?= nl2br(htmlspecialchars($donation['description'])) ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Image display section -->
                                <?php if (!empty($donation['image_path'])): ?>
                                    <?php
                                    $images = json_decode($donation['image_path'], true);
                                    if (is_array($images) && !empty($images)): ?>
                                        <div class="donation-images">
                                            <?php foreach ($images as $image): ?>
                                                <img src="<?= htmlspecialchars($image) ?>" alt="<?= htmlspecialchars($donation['item_name'] ?? 'Donation') ?>" class="donation-image" onclick="openPhotoZoom('<?= htmlspecialchars($image) ?>')">
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Photo Zoom Modal -->
    <div id="photoZoomModal" class="photo-zoom-modal" style="display:none;">
        <span class="close-modal" onclick="document.getElementById('photoZoomModal').style.display='none'">&times;</span>
        <img id="zoomedPhoto" class="zoomed-photo" src="" alt="Zoomed Photo">
    </div>
    
    <script>
        function openPhotoZoom(photoSrc) {
            const modal = document.getElementById('photoZoomModal');
            const zoomedPhoto = document.getElementById('zoomedPhoto');
            zoomedPhoto.src = photoSrc;
            modal.style.display = 'flex';
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
    </script>
</body>
</html>