    <?php
    session_start();
    require_once 'config.php';
    $conn = getDBConnection();

    $conn->query("SET time_zone = '+08:00'");

    // Ensure all server-side times are in Asia/Manila
    date_default_timezone_set('Asia/Manila');


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

    // --- Fetch user's projects (for request popup dropdown) --- //
    $user_projects = [];
    $proj_stmt = $conn->prepare("SELECT project_id, project_name FROM projects WHERE user_id = ?");
    $proj_stmt->bind_param("i", $user_id);
    $proj_stmt->execute();
    $proj_result = $proj_stmt->get_result();
    while ($proj = $proj_result->fetch_assoc()) {
        $user_projects[] = $proj;
    }


    // --- CATEGORY & SEARCH FILTERING LOGIC --- //
$selectedCategory = isset($_GET['category']) ? $_GET['category'] : 'All';
$selectedSubcategory = isset($_GET['subcategory']) ? $_GET['subcategory'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "
    SELECT d.*, u.first_name, u.last_name
    FROM donations d
    JOIN users u ON d.donor_id = u.user_id
    WHERE d.status = 'Available'
";

$params = [];
$types = '';

$mainCategories = ["Plastic", "Paper", "Metal", "Glass", "Electronic"];

// Category filtering
if ($selectedCategory !== 'All') {
    if ($selectedCategory === 'Other') {
        $placeholders = implode(',', array_fill(0, count($mainCategories), '?'));
        $sql .= " AND d.category NOT IN ($placeholders)";
        $params = array_merge($params, $mainCategories);
        $types .= str_repeat('s', count($mainCategories));
    } else {
        $sql .= " AND d.category = ?";
        $params[] = $selectedCategory;
        $types .= 's';
    }
}

// Subcategory filter
if (!empty($selectedSubcategory)) {
    $sql .= " AND d.subcategory = ?";
    $params[] = $selectedSubcategory;
    $types .= 's';
}

// 🔍 Search filter
if (!empty($search)) {
    $sql .= " AND (d.category LIKE CONCAT('%', ?, '%')
                OR d.subcategory LIKE CONCAT('%', ?, '%')
                OR d.item_name LIKE CONCAT('%', ?, '%'))";
    $params = array_merge($params, [$search, $search, $search]);
    $types .= 'sss';
}

$sql .= " ORDER BY d.donated_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$donations = $result->fetch_all(MYSQLI_ASSOC);


    // --- Fetch unique categories for buttons --- //
    $mainCategories = ["Plastic", "Paper", "Metal", "Glass", "Electronic"];
    $categories = [];
    $otherCategories = [];

    $catQuery = $conn->query("SELECT DISTINCT category FROM donations WHERE status='Available' ORDER BY category ASC");
    while ($catRow = $catQuery->fetch_assoc()) {
        $cat = trim($catRow['category']);
        if (!empty($cat)) {
            if (in_array($cat, $mainCategories)) {
                $categories[] = $cat;
            } else {
                $otherCategories[] = $cat;
            }
        }
    }

    // Always include all main categories, even if they have no donations yet
    $categories = array_unique(array_merge($mainCategories, $categories));


    // Add “Other” if there are any non-main categories
    if (!empty($otherCategories)) {
        $categories[] = "Other";
    }


    // Fallback if no categories in DB
    if (empty($categories)) {
        $categories = ["Plastic", "Paper", "Metal", "Glass", "Electronic"];
    }


    // --- Fetch subcategories for the selected category (DB + fallback) --- //
    $subcategories = [];

    if ($selectedCategory !== 'All') {
        $subcategories = [];

        // If "Other" is selected, fetch all subcategories not in main categories
        if ($selectedCategory === 'Other') {
            $placeholders = implode(',', array_fill(0, count($mainCategories), '?'));
            $sqlSub = "SELECT DISTINCT subcategory FROM donations WHERE status='Available' AND (category NOT IN ($placeholders))";
            $subQuery = $conn->prepare($sqlSub);
            $subQuery->bind_param(str_repeat('s', count($mainCategories)), ...$mainCategories);
        } else {
            // Normal category subcategories
            $subQuery = $conn->prepare("SELECT DISTINCT subcategory FROM donations WHERE category = ? AND status='Available'");
            $subQuery->bind_param("s", $selectedCategory);
        }

        $subQuery->execute();
        $subResult = $subQuery->get_result();
        while ($subRow = $subResult->fetch_assoc()) {
            if (!empty($subRow['subcategory'])) {
                $subcategories[] = $subRow['subcategory'];
            }
        }

        // Combine DB + default subcategories if applicable
        $defaultSubs = [
            'Plastic' => ['Plastic Bottles', 'Plastic Cups', 'Plastic Bags', 'Containers'],
            'Paper' => ['Cartons', 'Newspapers', 'Cardboard', 'Magazines', "Office Paper"],
            'Metal' => ['Aluminum Cans', 'Tin Cans', 'Scrap Metal', 'Aluminum Foil'],
            'Glass' => ['Glass Bottles', 'Broken Glass', 'Containers'],
            'Electronic' => ['Phones', 'Chargers', 'Wires', 'Old Appliances']
        ];

        if ($selectedCategory !== 'Other' && array_key_exists($selectedCategory, $defaultSubs)) {
            $subcategories = array_unique(array_merge($subcategories, $defaultSubs[$selectedCategory]));
        }
    }




    // --- Fetch recycled ideas --- //
    $ideas = [];
    $result = $conn->query("SELECT * FROM recycled_ideas ORDER BY posted_at DESC");
    while ($row = $result->fetch_assoc()) $ideas[] = $row;

    // Handle AJAX reply submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['donation_id'], $_POST['comment_text'])) {
        header('Content-Type: application/json');
        $donation_id = (int)$_POST['donation_id'];
        $user_id = (int)$_SESSION['user_id'];
        $comment_text = trim($_POST['comment_text']);
        $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;

        if ($donation_id && $comment_text !== '') {
            $now = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            $stmt = $conn->prepare("
                INSERT INTO comments (donation_id, user_id, comment_text, parent_id, created_at)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iisis", $donation_id, $user_id, $comment_text, $parent_id, $now);

            if ($stmt->execute()) {
                $comment_id = $stmt->insert_id;

                // Fetch user info for immediate UI update
                $u = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                $u->bind_param("i", $user_id);
                $u->execute();
                $user = $u->get_result()->fetch_assoc();

                echo json_encode([
                    'success' => true,
                    'comment_id' => $comment_id,
                    'user_name' => htmlspecialchars($user['first_name'] . ' ' . $user['last_name']),
                    'user_initial' => strtoupper(substr($user['first_name'], 0, 1)),
                    'comment_text' => htmlspecialchars($comment_text)
                ]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Failed to save reply.']);
        exit;
    }


    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Browse | EcoWaste</title>
        <link rel="stylesheet" href="assets/css/browse.css">
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
                        <li><a href="browse.php" class="active"><i class="fas fa-search"></i>Browse</a></li>
                        <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                        <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                        <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                        <li><a href="donations.php"><i class="fas fa-hand-holding-heart"></i>Donations</a></li>
                    </ul>
                </nav>
            </aside>
            
            <main class="main-content">
                <div class="search-bar">
                    <form action="browse.php" method="get">
                        <input type="text" name="search" placeholder="Search Donations..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                        <button type="submit">Search</button>
                    </form>
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
                        <div class="categories">
                            <div class="category-scroll-container">
                                <ul class="category-list">
                                    <li class="<?= $selectedCategory === 'All' ? 'active' : '' ?>">
                                        <a href="browse.php">All</a>
                                    </li>

                                    <?php
                                    // Always show main categories
                                    $mainCategories = ["Plastic", "Paper", "Metal", "Glass", "Electronic"];
                                    foreach ($mainCategories as $cat):
                                    ?>
                                        <li class="<?= $selectedCategory === $cat ? 'active' : '' ?>">
                                            <a href="browse.php?category=<?= urlencode($cat) ?>"><?= htmlspecialchars($cat) ?></a>
                                        </li>
                                    <?php endforeach; ?>

                                    <!-- Always show 'Other' -->
                                    <li class="<?= $selectedCategory === 'Other' ? 'active' : '' ?>">
                                        <a href="browse.php?category=Other">Other</a>
                                    </li>
                                </ul>
                            </div>

                        <?php if (!empty($subcategories)): ?>
                        <div class="subcategory-container">
                            <ul class="subcategory-list">
                                <?php foreach ($subcategories as $sub): ?>
                                    <li class="<?= $selectedSubcategory === $sub ? 'active' : '' ?>">
                                        <a href="browse.php?category=<?= urlencode($selectedCategory) ?>&subcategory=<?= urlencode($sub) ?>">
                                            <?= htmlspecialchars($sub) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                    </div>

                    
                    
                    <div class="section-card">
                        <h3>Available Donations</h3>
                        <div class="available">
                            <?php
                            // --- Fetch all comments for displayed donations and build a proper tree --- //
    $commentsByDonation = [];

    // build list of donation ids currently displayed (to limit query)
    $donationIds = array_map(function($d){ return (int)$d['donation_id']; }, $donations);
    if (!empty($donationIds)) {
        // prepare placeholders and types for mysqli
        $placeholders = implode(',', array_fill(0, count($donationIds), '?'));
        $types = str_repeat('i', count($donationIds));
        $stmtComments = $conn->prepare("
            SELECT c.*, u.first_name, u.last_name
            FROM comments c
            JOIN users u ON c.user_id = u.user_id
            WHERE c.donation_id IN ($placeholders)
            ORDER BY c.created_at ASC
        ");
        // bind params dynamically
        $stmtComments->bind_param($types, ...$donationIds);
        $stmtComments->execute();
        $resComments = $stmtComments->get_result();
        $allComments = $resComments->fetch_all(MYSQLI_ASSOC);
    } else {
        $allComments = [];
    }

    // normalize ids and build map
    $commentsById = [];
    foreach ($allComments as $c) {
        $c['comment_id'] = (int)$c['comment_id'];
        $c['donation_id'] = (int)$c['donation_id'];
        // normalize parent: null when empty/0
        $c['parent_id'] = empty($c['parent_id']) ? null : (int)$c['parent_id'];
        $c['replies'] = [];
        $commentsById[$c['comment_id']] = $c;
    }

    // attach replies to their parents, otherwise collect as top-level per donation
    foreach ($commentsById as $id => $comment) {
        $donationId = $comment['donation_id'];
        $parentId = $comment['parent_id'];

        if ($parentId && isset($commentsById[$parentId])) {
            // attach as child to parent (use reference)
            $commentsById[$parentId]['replies'][] = &$commentsById[$id];
        } else {
            // top-level comment for that donation
            if (!isset($commentsByDonation[$donationId])) $commentsByDonation[$donationId] = [];
            $commentsByDonation[$donationId][] = &$commentsById[$id];
        }
    }

    /**
     * Recursive renderer for a comments array (each item may have ['replies'] array)
     * $comments is an array of comment arrays (top-level or replies)
     */
    function renderComments(array $comments, int $sessionUserId) {
        foreach ($comments as $comment) {
            ?>
            <li class="comment-item" data-id="<?= (int)$comment['comment_id'] ?>" data-donation-id="<?= (int)$comment['donation_id'] ?>">
                <div class="comment-avatar"><?= strtoupper(substr(htmlspecialchars($comment['first_name'] ?? ''), 0, 1)) ?></div>
                <div class="comment-content">
                    <div class="comment-author"><?= htmlspecialchars(trim(($comment['first_name'] ?? '') . ' ' . ($comment['last_name'] ?? ''))) ?></div>
                    <div class="comment-text"><?= nl2br(htmlspecialchars($comment['comment_text'])) ?></div>
                    <?php
                    $localTime = new DateTime($comment['created_at'], new DateTimeZone('Asia/Manila'));
                    ?>
                    <div class="comment-time" data-time="<?= $localTime->format(DateTime::ATOM); ?>"></div>



                    <div class="comment-actions">
                        <button class="reply-btn" title="Reply to this comment">
                            <i class="fas fa-reply"></i> Reply
                        </button>
                        <?php if ((int)$comment['user_id'] === (int)$sessionUserId): ?>
                            <button class="edit-btn" title="Edit your comment">
                                <i class="fas fa-pen"></i> Edit
                            </button>
                            <button class="delete-btn" title="Delete this comment">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        <?php endif; ?>
                    </div>


                    <?php if (!empty($comment['replies'])): ?>
                        <ul class="reply-list">
                            <?php renderComments($comment['replies'], $sessionUserId); ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </li>
            <?php
        }
    }


                            ?>

                            <?php if (count($donations) === 0): ?>
                                <p>No donations available.</p>
                            <?php else: ?>
                                <?php foreach ($donations as $donation): ?>
                                    <div class="available-item">
                                        <!-- User Header -->
                                        <div class="donation-user-header">
                                            <?php
                                                $donor_stmt = $conn->prepare("SELECT user_id, first_name FROM users WHERE user_id = ?");
                                                $donor_stmt->bind_param("i", $donation['donor_id']);
                                                $donor_stmt->execute();
                                                $donor_result = $donor_stmt->get_result();
                                                $donor = $donor_result->fetch_assoc();
                                                $donor_initial = strtoupper(substr(htmlspecialchars($donor['first_name']), 0, 1));
                                            ?>
                                            <div class="user-avatar"><?= $donor_initial ?></div>
                                            <div class="user-info">
                                                <div class="user-name"><?= htmlspecialchars($donor['first_name']) ?></div>
                                                <div class="donation-meta">
                                                    <span class="category">
                                                        Category: 
                                                        <?= htmlspecialchars($donation['category']) ?>
                                                        <?php if (!empty($donation['subcategory'])): ?>
                                                            → <?= htmlspecialchars($donation['subcategory']) ?>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="time-ago"><?= htmlspecialchars(date('M d, Y', strtotime($donation['donated_at']))) ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Quantity -->
                                        <div class="item-quantity" data-donation-quantity-id="<?= (int)$donation['donation_id'] ?>">
                                            Quantity: <?= htmlspecialchars($donation['quantity']) ?>/<?= htmlspecialchars($donation['total_quantity']) ?>
                                        </div>


                                        <!-- Description -->
                                        <?php if (!empty($donation['description'])): ?>
                                            <div class="donation-description">
                                                <?= nl2br(htmlspecialchars($donation['description'])) ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Donation Images -->
                                        <?php if (!empty($donation['image_path'])): ?>
                                            <?php
                                            $images = json_decode($donation['image_path'], true);
                                            if (is_array($images) && !empty($images)): ?>
                                                <div class="donation-images">
                                                    <?php foreach ($images as $image): ?>
                                                        <img src="<?= htmlspecialchars($image) ?>" alt="Donation Image" class="donation-image donation-image-large">
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <!-- Buttons -->
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
                                        <!-- Hidden comments panel for this post (toggle by JS) -->
                                        <div class="comments-panel" id="comments-panel-<?= (int)$donation['donation_id'] ?>" style="display:none; margin-top:12px;">
                                            <ul class="comment-list" id="comment-list-<?= (int)$donation['donation_id'] ?>">
                                                <?php
                                                $donationComments = $commentsByDonation[$donation['donation_id']] ?? [];
                                                if (!empty($donationComments)) {
                                                    renderComments($donationComments, $_SESSION['user_id']);
                                                } else {
                                                    echo '<li class="no-comments">No comments yet. Be the first to comment!</li>';
                                                }
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


    <!-- === Request Donation Popup (Enhanced — matches Homepage style) === -->
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
            <p>Your request has been submitted successfully. Please wait for the donor’s response.</p>
            <button class="continue-btn" id="continueBtn">Continue</button>
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
    document.addEventListener("DOMContentLoaded", () => {

    /* ---------- Utilities ---------- */
    const qs = (sel) => document.querySelector(sel);
    const qsa = (sel) => Array.from(document.querySelectorAll(sel));
    const show = (el) => { if (el) el.style.display = 'flex'; };
    const hide = (el) => { if (el) el.style.display = 'none'; };
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe.replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[m]));
    }

   /* ---------- Tabs ---------- */
    function openTab(tabName) {
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.style.display = 'none';
        });

        // Show the selected tab content
        const targetTab = document.getElementById(tabName);
        if (targetTab) {
            targetTab.style.display = 'block';
        }

        // Update active button styles
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        const clickedBtn = Array.from(document.querySelectorAll('.tab-btn'))
            .find(btn => btn.getAttribute('onclick') === `openTab('${tabName}')`);
        if (clickedBtn) {
            clickedBtn.classList.add('active');
        }
    }



    /* ---------- Profile Dropdown ---------- */
    const userProfile = qs('#userProfile');
    if (userProfile) {
        userProfile.addEventListener('click', (e) => {
            e.stopPropagation();
            userProfile.classList.toggle('active');
        });
        document.addEventListener('click', (e) => {
            if (!userProfile.contains(e.target)) {
                userProfile.classList.remove('active');
            }
        });
    }

    /* ---------- Request Donation Popup ---------- */
    let currentAvailable = 0;

    function openRequestPopup(donationId, wasteName, available) {
        qs('#popupDonationId').value = donationId;
        qs('#popupWasteName').textContent = wasteName;
        qs('#popupAvailable').textContent = available;
        qs('#quantityClaim').value = 1;
        currentAvailable = parseInt(available) || 0;
        show(qs('#requestPopup'));
    }
    function closeRequestPopup() { hide(qs('#requestPopup')); }
    function closeRequestSuccessPopup() { hide(qs('#requestSuccessPopup')); }

    function updateQuantity(change) {
    const input = qs('#quantityClaim');
    if (!input) return;

    let val = parseInt(input.value) || 1;
    val += change;

    if (val < 1) val = 1;
    if (val > currentAvailable && currentAvailable > 0) val = currentAvailable;

    input.value = val;
    }


    /* Attach request button events */
    qsa('.request-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.donationId;
            if (!id) return;
            const available = this.dataset.available || 0;
            const categoryEl = this.closest('.available-item')?.querySelector('.category');
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

    /* AJAX submit request */
    const requestForm = qs('#requestFormAjax');
    if (requestForm) {
        requestForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const fd = new FormData(requestForm);
            fd.append('submit_request_donation', '1');
            fetch('homepage.php', { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    hide(qs('#requestPopup'));
                    show(qs('#requestSuccessPopup'));
                } else {
                    alert(data.message || 'Request failed.');
                }
            })
            .catch(() => alert('Network error.'));
        });
    }

    // === Quantity Input Controls ===
    const quantityInput = qs('#quantityClaim');
    const btnMinus = qs('#btnMinus');
    const btnPlus = qs('#btnPlus');

    if (quantityInput) {
    // Prevent typing e, +, -, ., or other non-numeric characters
    quantityInput.addEventListener('keydown', function(e) {
        if (['e', 'E', '+', '-', '.'].includes(e.key)) e.preventDefault();
    });

    // Update via + and - buttons
    btnMinus?.addEventListener('click', () => updateQuantity(-1));
    btnPlus?.addEventListener('click', () => updateQuantity(1));
    }

    // Attach listeners to the new Cancel and Continue buttons (IDs added earlier)
    const cancelBtn = qs('#cancelRequest');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', (e) => {
            e.preventDefault();
            closeRequestPopup();
        });
    }
    const continueBtn = qs('#continueBtn');
    if (continueBtn) {
        continueBtn.addEventListener('click', (e) => {
            e.preventDefault();
            closeRequestSuccessPopup();
        });
    }


    /* ---------- Comments ---------- */
    qsa('.comment-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.donationId;
            const panel = qs(`#comments-panel-${id}`);
            if (panel) panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
        });
    });

    qsa('.comment-form-ajax').forEach(form => {
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

            fetch('homepage.php', { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const list = qs(`#comment-list-${id}`);
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
                } else alert(data.message || 'Failed to post comment.');
            })
            .catch(() => alert('Network error.'));
        });
    });

    /* ---------- Feedback Modal ---------- */
    const feedbackBtn = qs('#feedbackBtn');
    const feedbackModal = qs('#feedbackModal');
    const feedbackCloseBtn = qs('#feedbackCloseBtn');
    const emojiOptions = qsa('.emoji-option');
    const feedbackSubmitBtn = qs('#feedbackSubmitBtn');
    const feedbackText = qs('#feedbackText');
    const ratingError = qs('#ratingError');
    const textError = qs('#textError');
    const thankYouMessage = qs('#thankYouMessage');
    const feedbackForm = qs('#feedbackForm');
    const spinner = qs('#spinner');
    let selectedRating = 0;

    if (feedbackBtn && feedbackModal) {
        feedbackBtn.addEventListener('click', () => {
            feedbackModal.style.display = 'flex';
            feedbackForm.style.display = 'block';
            thankYouMessage.style.display = 'none';
        });

        feedbackCloseBtn?.addEventListener('click', () => feedbackModal.style.display = 'none');
        window.addEventListener('click', (e) => {
            if (e.target === feedbackModal) feedbackModal.style.display = 'none';
        });

        emojiOptions.forEach(opt => {
            opt.addEventListener('click', () => {
                emojiOptions.forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
                selectedRating = opt.getAttribute('data-rating');
                ratingError.style.display = 'none';
            });
        });

        feedbackSubmitBtn?.addEventListener('click', (e) => {
            e.preventDefault();
            let valid = true;
            if (!selectedRating) { ratingError.style.display = 'block'; valid = false; }
            if (feedbackText.value.trim() === '') { textError.style.display = 'block'; valid = false; }
            else textError.style.display = 'none';
            if (!valid) return;

            spinner.style.display = 'inline-block';
            feedbackSubmitBtn.disabled = true;

            fetch('feedback_process.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `rating=${selectedRating}&feedback=${encodeURIComponent(feedbackText.value)}`
            })
            .then(res => res.json())
            .then(data => {
                spinner.style.display = 'none';
                feedbackSubmitBtn.disabled = false;
                if (data.status === 'success') {
                    feedbackForm.style.display = 'none';
                    thankYouMessage.style.display = 'block';
                    setTimeout(() => {
                        feedbackModal.style.display = 'none';
                        feedbackForm.style.display = 'block';
                        thankYouMessage.style.display = 'none';
                        feedbackText.value = '';
                        selectedRating = 0;
                        emojiOptions.forEach(o => o.classList.remove('selected'));
                    }, 3000);
                } else alert(data.message || 'Failed to submit feedback.');
            })
            .catch(() => {
                spinner.style.display = 'none';
                feedbackSubmitBtn.disabled = false;
                alert('Failed to submit feedback. Please try again.');
            });
        });
    }

        /* ---------- Reply / Edit / Delete for Comments ---------- */
        document.addEventListener('click', async (e) => {
            const item = e.target.closest('.comment-item');
            if (!item) return;

            // === REPLY ===
            if (e.target.classList.contains('reply-btn')) {
                const existingForm = document.querySelector('.reply-box');
                if (existingForm) existingForm.remove();

                const replyForm = document.createElement('form');
                replyForm.className = 'reply-box';
                replyForm.innerHTML = `
                    <textarea name="reply" class="comment-input" placeholder="Write a reply..." required></textarea>
                    <button type="submit" class="comment-submit">Reply</button>
                `;
                item.insertAdjacentElement('afterend', replyForm);
                replyForm.reply.focus();

                replyForm.addEventListener('submit', async (ev) => {
                    ev.preventDefault();
                    const replyText = replyForm.reply.value.trim();
                    if (!replyText) return;

                    const donationId = item.dataset.donationId;
                    const parentId = item.dataset.id;

                    try {
                        const response = await fetch('browse.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `donation_id=${encodeURIComponent(donationId)}&comment_text=${encodeURIComponent(replyText)}&parent_id=${encodeURIComponent(parentId)}`
                        });
                        const result = await response.json();

                        if (result.success) {
                            let replyList = item.querySelector('.reply-list');
                            if (!replyList) {
                                replyList = document.createElement('ul');
                                replyList.className = 'reply-list';
                                item.appendChild(replyList);
                            }

                            const li = document.createElement('li');
                            li.className = 'comment-item';
                            li.dataset.id = result.comment_id;
                            li.dataset.donationId = donationId;

                            // Generate an ISO timestamp in Manila time
                            const now = new Date().toISOString();

                            li.innerHTML = `
                                <div class="comment-avatar">${result.user_initial}</div>
                                <div class="comment-content">
                                    <div class="comment-author">${result.user_name}</div>
                                    <div class="comment-text">${result.comment_text}</div>
                                    <div class="comment-time" data-time="${now}">Just now</div>
                                    <div class="comment-actions">
                                        <button class="reply-btn">Reply</button>
                                        <button class="edit-btn">Edit</button>
                                        <button class="delete-btn">Delete</button>
                                    </div>
                                </div>
                            `;


                            // Append reply
                            replyList.appendChild(li);
                            replyForm.remove();

                            // ✅ Immediately recalculate visible times
                            if (typeof refreshAllCommentTimes === 'function') {
                                refreshAllCommentTimes(); // instant update
                            }

                        } else {
                            alert(result.message || 'Failed to post reply.');
                        }
                    } catch (err) {
                        console.error(err);
                        alert('Error posting reply.');
                    }
                });
            }

            // === EDIT ===
            else if (e.target.classList.contains('edit-btn')) {
                const textEl = item.querySelector('.comment-text');
                const btn = e.target;
                const originalText = textEl.textContent.trim();
                btn.textContent = 'Save';
                textEl.contentEditable = true;
                textEl.focus();

                const saveOnce = async () => {
                    const newText = textEl.textContent.trim();
                    if (!newText) return alert('Empty comment.');

                    const fd = new FormData();
                    fd.append('id', item.dataset.id);
                    fd.append('content', newText);

                    const res = await fetch('edit_comment.php', { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data.success) {
                        textEl.textContent = newText;
                        textEl.contentEditable = false;
                        btn.textContent = 'Edit';
                    } else {
                        alert(data.message || 'Edit failed.');
                        textEl.textContent = originalText;
                        textEl.contentEditable = false;
                        btn.textContent = 'Edit';
                    }
                    btn.removeEventListener('click', saveOnce);
                };

                btn.addEventListener('click', saveOnce, { once: true });
            }

            // === DELETE ===
            else if (e.target.classList.contains('delete-btn')) {
                if (!confirm('Delete this comment (and its replies)?')) return;

                const fd = new FormData();
                fd.append('id', item.dataset.id);

                try {
                    const res = await fetch('delete_comment.php', { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data.success) {
                        item.style.transition = 'opacity 0.3s';
                        item.style.opacity = '0';
                        setTimeout(() => item.remove(), 300);
                    } else {
                        alert(data.message || 'Delete failed.');
                    }
                } catch (err) {
                    console.error(err);
                    alert('Network error while deleting.');
                }
            }
        });
    });

    /* ---------- Comment Time Display (Accurate + Auto-update) ---------- */
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

    // Older posts → full readable date in Manila timezone
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

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshAllCommentTimes();
});


// Run once + refresh every 1 minute
refreshAllCommentTimes();
setInterval(refreshAllCommentTimes, 60000);


    </script>

    </body>
    </html>