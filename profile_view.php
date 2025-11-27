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

    
        .profile-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 120px;
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
            color: #fff938;
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
                    <!-- Enhanced User Profile Header -->
                    <div class="profile-header">
                        <div class="profile-cover">
                            <div class="profile-avatar">
                                <div class="avatar-circle">
                                    <?= strtoupper(substr(htmlspecialchars($viewed_user['first_name'] ?? 'U'), 0, 1)) ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="profile-info">
                            <h1 class="profile-name"><?= htmlspecialchars(($viewed_user['first_name'] ?? 'User') . ' ' . ($viewed_user['last_name'] ?? '')) ?></h1>
                            <p class="profile-username">@<?= htmlspecialchars($viewed_user['first_name'] ?? 'User') ?></p>
                            <div class="profile-location">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars($viewed_user['address'] ?? 'Address not provided') ?></span>
                            </div>
                            <div class="member-since-profile">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Member since 
                                    <?php 
                                        if (!empty($viewed_user['created_at'])) {
                                            echo htmlspecialchars(date('F Y', strtotime($viewed_user['created_at'])));
                                        } else {
                                            echo 'Unknown date';
                                        }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Enhanced Profile Stats -->
                    <div class="profile-section">
                        <h2 class="section-title">Profile Stats</h2>
                        <div class="profile-stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-recycle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-value"><?= htmlspecialchars($viewed_user_stats['items_recycled'] ?? 0) ?></div>
                                    <div class="stat-label">Items Recycled</div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-hand-holding-heart"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-value"><?= htmlspecialchars($viewed_user_stats['items_donated'] ?? 0) ?></div>
                                    <div class="stat-label">Items Donated</div>
                                </div>
                            </div>
                            
                            <div class="stat-card highlight">
                                <div class="stat-icon">
                                    <i class="fas fa-star"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-value"><?= htmlspecialchars($viewed_user['points'] ?? 0) ?></div>
                                    <div class="stat-label">Total Points</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Enhanced Eco Badges -->
                    <div class="profile-section">
                        <div class="badges-section-header">
                            <h2 class="badges-section-title">Eco Badges</h2>
                        </div>
                        
                        <div class="badges-container">
                            <div class="badges-grid limited" id="badgesGrid">
                            <?php
                            // Fetch badges earned by the viewed user with badge info
                            $stmt = $conn->prepare("
                                SELECT b.badge_id, b.badge_name, b.description, b.icon 
                                FROM user_badges ub
                                JOIN badges b ON ub.badge_id = b.badge_id
                                WHERE ub.user_id = ?
                                ORDER BY b.badge_id ASC
                            ");
                            $stmt->bind_param("i", $viewed_user_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $all_badges = [];
                            $display_limit = 4;

                            // Check if the user has earned any badges
                            if ($result->num_rows === 0):
                            ?>
                                <div class="no-content">No badges earned yet. Start recycling and donating to earn badges!</div>
                            <?php
                            else:
                                $badge_count = 0;
                                while ($badge = $result->fetch_assoc()):
                                    $all_badges[] = $badge;
                                    $badge_count++;
                                    $badge_class = '';
                                    if ($badge_count === 1) $badge_class = 'gold';
                                    elseif ($badge_count === 2) $badge_class = 'silver';
                                    elseif ($badge_count === 3) $badge_class = 'bronze';
                                    
                                    // Only display up to the limit
                                    if ($badge_count <= $display_limit):
                            ?>
                                <div class="badge-card">
                                    <div class="badge-icon <?= $badge_class ?>">
                                        <i class="<?= htmlspecialchars($badge['icon']); ?>"></i>
                                    </div>
                                    <div class="badge-info">
                                        <h3>
                                            <?= htmlspecialchars($badge['badge_name']); ?>
                                        </h3>
                                        <p>
                                            <?= htmlspecialchars($badge['description']); ?>
                                        </p>
                                        <span class="badge-status earned">
                                            Earned
                                        </span>
                                    </div>
                                </div>
                            <?php
                                    endif;
                                endwhile;
                            endif;
                            $stmt->close();
                            ?>
                            </div>
                            
                            <?php if (count($all_badges) > $display_limit): ?>
                            <div class="badges-more" id="badgesMore">
                                <div class="badges-grid">
                                <?php 
                                // Display remaining badges
                                for ($i = $display_limit; $i < count($all_badges); $i++):
                                    $badge = $all_badges[$i];
                                    $badge_class = '';
                                    if ($i === 0) $badge_class = 'gold';
                                    elseif ($i === 1) $badge_class = 'silver';
                                    elseif ($i === 2) $badge_class = 'bronze';
                                ?>
                                    <div class="badge-card" >
                                        <div class="badge-icon <?= $badge_class ?>" >
                                            <i class="<?= htmlspecialchars($badge['icon']); ?>"></i>
                                        </div>
                                        <div class="badge-info">
                                            <h3>
                                                <?= htmlspecialchars($badge['badge_name']); ?>
                                            </h3>
                                            <p>
                                                <?= htmlspecialchars($badge['description']); ?>
                                            </p>
                                            <span class="badge-status earned">
                                                Earned
                                            </span>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                                </div>
                            </div>
                            
                            <div class="view-toggle">
                                <button class="toggle-badges-btn" id="toggleBadgesBtn" onclick="toggleMoreBadges()">
                                    Show More Badges
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>


                    <!-- Enhanced Recent Donations -->
                    <div class="profile-section">
                        <h2 class="section-title">Recent Donations</h2>
                        <?php if (count($viewed_user_donations) === 0): ?>
                            <div class="no-content">No donations yet. Start making donations to help the environment!</div>
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

                                <!-- Action Buttons -->
                                <div class="donation-actions">
                                    <?php if ($donation['donor_id'] == $_SESSION['user_id']): ?>
                                        <button class="comment-btn" data-donation-id="<?= (int)$donation['donation_id'] ?>">
                                            <i class="fas fa-comment"></i> Comments
                                        </button>
                                    <?php else: ?>
                                        <button class="request-btn"
                                                data-donation-id="<?= (int)$donation['donation_id'] ?>"
                                                data-available="<?= (int)$donation['quantity'] ?>"
                                                data-total="<?= (int)$donation['total_quantity'] ?>">
                                            Request Donation
                                        </button>
                                        <button class="comment-btn" data-donation-id="<?= (int)$donation['donation_id'] ?>">
                                            <i class="fas fa-comment"></i> Comments
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <!-- Hidden comments panel for this post (toggle by JS) -->
                                <div class="comments-panel" id="comments-panel-<?= (int)$donation['donation_id'] ?>" style="display:none; margin-top:12px;">
                                    <ul class="comment-list" id="comment-list-<?= (int)$donation['donation_id'] ?>">
                                        <?php
                                        // You'll need to fetch comments for each donation here, similar to browse.php
                                        // For now, showing a placeholder
                                        echo '<li class="no-comments">No comments yet. Be the first to comment!</li>';
                                        ?>
                                    </ul>

                                    <form class="comment-form-ajax" data-donation-id="<?= (int)$donation['donation_id'] ?>" onsubmit="return false;">
                                        <input type="hidden" name="donation_id" value="<?= (int)$donation['donation_id'] ?>">
                                        <textarea name="comment_text" class="comment-input" placeholder="Write a comment..." required></textarea>
                                        <button type="submit" class="comment-submit-ajax">Post Comment</button>
                                        <span class="comment-spinner" style="display:none;margin-left:8px;">⏳</span>
                                    </form>
                                </div>
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

    <!-- Request Donation Popup -->
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
                    <button type="button" id="btnMinus"
                    style="width:32px;height:32px;border:none;background:#f0f0f0;border-radius:6px;cursor:pointer;font-size:18px;font-weight:bold;">−</button>

                    <input type="number" id="quantityClaim" name="quantity_claim" value="1" min="1"
                    style="width:60px;text-align:center;border:1.5px solid #ccc;border-radius:6px;padding:6px;appearance:none;-moz-appearance:textfield;">

                    <button type="button" id="btnPlus"
                    style="width:32px;height:32px;border:none;background:#f0f0f0;border-radius:6px;cursor:pointer;font-size:18px;font-weight:bold;">+</button>
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
                <button type="button" id="cancelRequest" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Request Success Popup -->
<div id="requestSuccessPopup" class="popup-container" style="display:none;">
    <div class="popup-content success-popup">
        <h2>Request Sent!</h2>
        <p>Your request has been submitted successfully. Please wait for the donor's response.</p>
        <button class="continue-btn" id="continueBtn">Continue</button>
    </div>
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

        document.addEventListener("DOMContentLoaded", function () {
    // Grab elements
    const feedbackBtn = document.getElementById("feedbackBtn");
    const feedbackModal = document.getElementById("feedbackModal");
    const feedbackCloseBtn = document.getElementById("feedbackCloseBtn");
    const emojiOptions = feedbackModal ? feedbackModal.querySelectorAll(".emoji-option") : [];
    const feedbackSubmitBtn = document.getElementById("feedbackSubmitBtn");
    const feedbackText = document.getElementById("feedbackText");
    const ratingError = document.getElementById("ratingError");
    const textError = document.getElementById("textError");
    const thankYouMessage = document.getElementById("thankYouMessage");
    const feedbackForm = document.getElementById("feedbackForm");
    const spinner = document.getElementById("spinner");

    if (!feedbackBtn || !feedbackModal || !feedbackSubmitBtn || !feedbackText) return;

    let selectedRating = 0;

    // Open modal
    feedbackBtn.addEventListener("click", () => {
        feedbackModal.style.display = "flex";
        feedbackForm.style.display = "block";
        thankYouMessage.style.display = "none";
    });

    // Close modal
    feedbackCloseBtn?.addEventListener("click", () => feedbackModal.style.display = "none");
    window.addEventListener("click", e => {
        if (e.target === feedbackModal) feedbackModal.style.display = "none";
    });

    // Emoji rating selection
    emojiOptions.forEach(option => {
        option.addEventListener("click", () => {
            emojiOptions.forEach(o => o.classList.remove("selected"));
            option.classList.add("selected");
            selectedRating = option.getAttribute("data-rating");
            ratingError.style.display = "none";
        });
    });

    // Submit feedback
    feedbackSubmitBtn.addEventListener("click", e => {
        e.preventDefault();

        let valid = true;
        if (selectedRating === 0) { ratingError.style.display = "block"; valid = false; }
        if (feedbackText.value.trim() === "") { textError.style.display = "block"; valid = false; }
        else { textError.style.display = "none"; }

        if (!valid) return;

        spinner.style.display = "inline-block";
        feedbackSubmitBtn.disabled = true;

        // AJAX POST
        fetch("feedback_process.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `rating=${selectedRating}&feedback=${encodeURIComponent(feedbackText.value)}`
        })
        .then(res => res.json())
        .then(data => {
            spinner.style.display = "none";
            feedbackSubmitBtn.disabled = false;

            if (data.status === "success") {
                feedbackForm.style.display = "none";
                thankYouMessage.style.display = "block";

                // Reset after 3 seconds
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

function toggleMoreBadges() {
    const badgesMore = document.getElementById('badgesMore');
    const toggleBtn = document.getElementById('toggleBadgesBtn');
    const badgesGrid = document.getElementById('badgesGrid');
    
    if (badgesMore && toggleBtn) {
        if (badgesMore.classList.contains('show')) {
            badgesMore.classList.remove('show');
            toggleBtn.innerHTML = 'Show More Badges <i class="fas fa-chevron-down"></i>';
            badgesGrid.classList.add('limited');
        } else {
            badgesMore.classList.add('show');
            toggleBtn.innerHTML = 'Show Less Badges <i class="fas fa-chevron-up"></i>';
            badgesGrid.classList.remove('limited');
        }
    }
}

/* ---------- Comments ---------- */
document.addEventListener('DOMContentLoaded', () => {
    // Comment button toggle
    document.querySelectorAll('.comment-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.donationId;
            const panel = document.querySelector(`#comments-panel-${id}`);
            if (panel) panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
        });
    });

    // Comment form submission
    document.querySelectorAll('.comment-form-ajax').forEach(form => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const id = form.dataset.donationId;
            const textarea = form.querySelector('textarea[name="comment_text"]');
            const text = textarea.value.trim();
            if (!text) return;

            const fd = new FormData();
            fd.append('donation_id', id);
            fd.append('comment_text', text);
            fd.append('submit_comment', '1');

            fetch('homepage.php', { 
                method: 'POST', 
                body: fd, 
                headers: {'X-Requested-With':'XMLHttpRequest'} 
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const list = document.querySelector(`#comment-list-${id}`);
                    const li = document.createElement('li');
                    li.className = 'comment-item';
                    li.innerHTML = `
                        <div class="comment-avatar">U</div>
                        <div class="comment-content">
                            <div class="comment-author">You</div>
                            <div class="comment-text">${escapeHtml(text)}</div>
                            <div class="comment-time">Just now</div>
                        </div>`;
                    list.insertBefore(li, list.firstChild);
                    textarea.value = '';
                    
                    // Remove "no comments" message if it exists
                    const noComments = list.querySelector('.no-comments');
                    if (noComments) noComments.remove();
                } else {
                    alert(data.message || 'Failed to post comment.');
                }
            })
            .catch(() => alert('Network error.'));
        });
    });

    // Utility function for HTML escaping
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe.replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[m]));
    }
});

/* ---------- Request Donation Popup ---------- */
let currentAvailable = 0;

function openRequestPopup(donationId, wasteName, available) {
    document.querySelector('#popupDonationId').value = donationId;
    document.querySelector('#popupWasteName').textContent = wasteName;
    document.querySelector('#popupAvailable').textContent = available;
    document.querySelector('#quantityClaim').value = 1;
    currentAvailable = parseInt(available) || 0;
    document.querySelector('#requestPopup').style.display = 'flex';
}

function closeRequestPopup() { 
    document.querySelector('#requestPopup').style.display = 'none'; 
}

function closeRequestSuccessPopup() { 
    document.querySelector('#requestSuccessPopup').style.display = 'none'; 
}

function updateQuantity(change) {
    const input = document.querySelector('#quantityClaim');
    if (!input) return;

    let val = parseInt(input.value) || 1;
    val += change;

    if (val < 1) val = 1;
    if (val > currentAvailable && currentAvailable > 0) val = currentAvailable;

    input.value = val;
}

// Attach request button events
document.querySelectorAll('.request-btn').forEach(btn => {
    if (btn.classList.contains('comment-btn')) return; // Skip comment buttons
    
    btn.addEventListener('click', function() {
        const id = this.dataset.donationId;
        if (!id) return;
        const available = this.dataset.available || 0;
        const categoryEl = this.closest('.donation-post')?.querySelector('.category');
        let wasteText = 'Unknown';
        if (categoryEl) {
            const text = categoryEl.textContent.replace('Category:', '').trim();
            const parts = text.split('→').map(p => p.trim());
            if (parts.length === 2) wasteText = `${parts[1]} (${parts[0]})`;
            else wasteText = text;
        }
        openRequestPopup(id, wasteText, available);
    });
});

// Quantity controls
const quantityInput = document.querySelector('#quantityClaim');
const btnMinus = document.querySelector('#btnMinus');
const btnPlus = document.querySelector('#btnPlus');

if (quantityInput) {
    quantityInput.addEventListener('keydown', function(e) {
        if (['e', 'E', '+', '-', '.'].includes(e.key)) e.preventDefault();
    });

    btnMinus?.addEventListener('click', () => updateQuantity(-1));
    btnPlus?.addEventListener('click', () => updateQuantity(1));
}

// Cancel and Continue buttons
const cancelBtn = document.querySelector('#cancelRequest');
if (cancelBtn) {
    cancelBtn.addEventListener('click', (e) => {
        e.preventDefault();
        closeRequestPopup();
    });
}

const continueBtn = document.querySelector('#continueBtn');
if (continueBtn) {
    continueBtn.addEventListener('click', (e) => {
        e.preventDefault();
        closeRequestSuccessPopup();
    });
}

// Photo zoom function (keep your existing function)
function openPhotoZoom(photoSrc) {
    const modal = document.getElementById('photoZoomModal');
    const zoomedPhoto = document.getElementById('zoomedPhoto');
    zoomedPhoto.src = photoSrc;
    modal.style.display = 'flex';
}
    </script>
</body>
</html>