<?php
session_start();
require_once 'config.php';

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];


// Fetch My Donations
$myDonations = [];
$stmt = $conn->prepare("
    SELECT 
        donation_id,
        status,
        quantity,
        total_quantity,
        donated_at,
        delivered_at,
        description,
        category,
        subcategory
    FROM donations 
    WHERE donor_id = ?
    ORDER BY donated_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // fallback for empty subcategory
    if (empty($row['subcategory'])) $row['subcategory'] = '—';
    if (empty($row['category'])) $row['category'] = '—';
    $myDonations[] = $row;
}
$stmt->close();


// Fetch all requests for my donations (with claimer + project)
$donationRequestsMap = [];
$stmt = $conn->prepare("
    SELECT dr.donation_id,
           CONCAT(u.first_name, ' ', u.last_name) AS claimer_name,
           p.project_name,
           dr.requested_at
    FROM donation_requests dr
    JOIN users u ON dr.user_id = u.user_id
    LEFT JOIN projects p ON dr.project_id = p.project_id
    WHERE dr.donation_id IN (
        SELECT donation_id FROM donations WHERE donor_id = ?
    )
    ORDER BY dr.requested_at ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $donationRequestsMap[$row['donation_id']][] = $row;
}

// Fetch Requests for My Donations (requests from others for my donations)
$requestsForMe = [];
$stmt = $conn->prepare("
    SELECT 
        dr.request_id,
        dr.status AS request_status,
        dr.quantity_claim,
        dr.urgency_level,
        dr.requested_at,
        d.subcategory,
        d.category,
        d.total_quantity,
        u.first_name,
        u.last_name,
        u.address,
        p.project_name
    FROM donation_requests dr
    JOIN donations d ON dr.donation_id = d.donation_id
    JOIN users u ON dr.user_id = u.user_id
    LEFT JOIN projects p ON dr.project_id = p.project_id
    WHERE d.donor_id = ?
    ORDER BY dr.requested_at DESC
");


$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $requestsForMe[] = $row;


// Fetch My Requested Donations (requests I made to others)
$myRequests = [];
$stmt = $conn->prepare("
    SELECT 
        dr.request_id,
        dr.status,
        dr.quantity_claim,
        dr.urgency_level,
        dr.requested_at,
        dr.delivery_status,
        d.subcategory,
        d.category, 
        d.total_quantity,
        u.first_name AS donor_first_name,
        u.last_name AS donor_last_name,
        p.project_name
    FROM donation_requests dr
    JOIN donations d ON dr.donation_id = d.donation_id
    JOIN users u ON d.donor_id = u.user_id
    LEFT JOIN projects p ON dr.project_id = p.project_id
    WHERE dr.user_id = ?
    ORDER BY dr.requested_at DESC
");


// ✅ Bind the user_id parameter before executing
$stmt->bind_param("i", $user_id);

$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $myRequests[] = $row;
}
$stmt->close();


// Fetch My Received Donations
$receivedDonations = [];
$stmt = $conn->prepare("SELECT *, subcategory FROM donations WHERE receiver_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $receivedDonations[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donations | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/donations.css">
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
                    <li><a href="donations.php" class="active"><i class="fas fa-hand-holding-heart"></i>Donations</a></li>
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


            <div class="donations-container">
                <div class="page-header">
                    <h2 class="page-title">Donation Management</h2>
                </div>
                <p class="page-description" id="tab-description">
                    Here are all the donations you created. You can manage them here.
                </p>

            
                <div class="tabs-container">
                    <div class="tabs">
                        <button class="tab-btn tab-btn-active" onclick="showTab('my-donations', this)">My Donations</button>
                        <button class="tab-btn" onclick="showTab('requests-for-me', this)">Requests for My Donations</button>
                        <button class="tab-btn" onclick="showTab('my-requests', this)">My Requested Donations</button>
                        <button class="tab-btn" onclick="showTab('received-donations', this)">Received Donations</button>
                    </div>
                </div>

                <div class="tab-content-wrapper">

                    <!-- My Donations Tab -->
                    <div id="my-donations" class="tab-content tab-active">
                        <div class="donations-grid">
                            <?php if (!empty($myDonations)): ?>
                                <?php foreach ($myDonations as $d): ?>
                                    <div class="donation-card">
                                        <div class="donation-header">
                                            <span class="donation-title"><?= htmlspecialchars($d['subcategory']) ?></span>
                                            <span class="donation-status 
                                                <?= strtolower($d['status']) == 'pending' ? 'status-pending' :
                                                (strtolower($d['status']) == 'completed' ? 'status-completed' :
                                                (strtolower($d['status']) == 'requested' ? 'status-requested' : 'status-pending')) ?>">
                                                <?= htmlspecialchars($d['status']) ?>
                                            </span>
                                        </div>
                                        <div class="donation-details">
                                            <p class="donation-detail"><strong>Donated:</strong> 
                                                <?= $d['donated_at'] ? date("M d, Y", strtotime($d['donated_at'])) : '—' ?>
                                            </p>
                                            <p class="donation-detail"><strong>Types of waste:</strong> 
                                                <?= htmlspecialchars($d['category'] ?? '—') ?>
                                            </p>
                                            <p class="donation-detail"><strong>Quantity:</strong> 
                                                <?= $d['quantity'] ?>/<?= $d['total_quantity'] ?>
                                            </p>
                                            <p class="donation-detail description"><strong>Description:</strong> 
                                                <?= htmlspecialchars($d['description'] ?? '—') ?>
                                            </p>

                                            
                                            <?php if (!empty($donationRequestsMap[$d['donation_id']])): ?>
                                                <p class="donation-detail"><strong>Requested by:</strong></p>
                                                <ul class="donation-requests-list">
                                                    <?php foreach ($donationRequestsMap[$d['donation_id']] as $req): ?>
                                                        <li>
                                                            <?= htmlspecialchars($req['claimer_name']) ?> 
                                                            (Project: <?= htmlspecialchars($req['project_name'] ?? '—') ?>, 
                                                            Requested at: <?= date("M d, Y H:i", strtotime($req['requested_at'])) ?>)
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <p class="donation-detail"><strong>Requested by:</strong> None yet</p>
                                            <?php endif; ?>

                                            <p class="donation-detail"><strong>Delivered:</strong> 
                                                <?= $d['delivered_at'] ? date("M d, Y", strtotime($d['delivered_at'])) : 'Pending' ?>
                                            </p>
                                        </div>
                                        <div class="card-actions">
                                            <a href="#" class="view-details" data-id="<?= $d['donation_id'] ?>">View Details</a>
                                            <button class="delete-btn" data-id="<?= $d['donation_id'] ?>">Delete</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-box-open"></i>
                                    <h3>No donations yet</h3>
                                    <p>You haven't created any donations yet. Start by creating your first donation!</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>


<!-- My Requested Donations Tab -->
<div id="my-requests" class="tab-content">
    <?php if (!empty($myRequests)): ?>
        <?php foreach ($myRequests as $rq): ?>
            <?php $status = strtolower(trim($rq['status'] ?? '')); ?>
            <div class="donation-card" data-request-id="<?= htmlspecialchars($rq['request_id']); ?>">
                <div class="donation-header">
                    <span class="donation-title"><?= htmlspecialchars($rq['subcategory'] ?? 'Donation Request') ?></span>

                    <!-- Request Status Badge (top-right) -->
                    <span class="donation-status 
                        <?= $status == 'pending' ? 'status-pending' :
                           ($status == 'approved' ? 'status-approved' :
                           ($status == 'requested' ? 'status-received' : '')) ?>">
                        <?= htmlspecialchars(ucwords($rq['status'])) ?>
                    </span>
                </div>

                <div class="donation-details">
                    <?php if ($status === 'pending'): ?>
                        <!-- FULL DETAILS (for Pending requests only) -->
                        <p><strong>Donation By:</strong> <?= htmlspecialchars($rq['donor_first_name'] . ' ' . $rq['donor_last_name']) ?></p>
                        <p><strong>Project Name:</strong> <?= htmlspecialchars($rq['project_name'] ?? '—') ?></p>
                        <p><strong>Type of Waste:</strong> <?= htmlspecialchars($rq['category'] ?? '—') ?></p>
                        <p><strong>Quantity:</strong> 
                            <?= htmlspecialchars($rq['quantity_claim'] ?? 0) ?> /
                            <?= htmlspecialchars($rq['total_quantity'] ?? 0) ?> Units
                        </p>
                        <p><strong>Request Date:</strong> <?= date("M d, Y H:i", strtotime($rq['requested_at'])) ?></p>
                        <p><strong>Urgency Level:</strong> <?= htmlspecialchars($rq['urgency_level'] ?? 'Normal') ?></p>
                        <p><strong>Status:</strong> <?= htmlspecialchars(ucwords($rq['status'])) ?></p>

                    <?php else: ?>
                        <!-- SIMPLIFIED DETAILS (for Approved and beyond) -->
                        <?php 
                        $deliveryStatus = strtolower($rq['delivery_status'] ?? 'pending');
                        $showDeliveryDate = in_array($deliveryStatus, [
                            'waiting for pickup',
                            'at sorting facility',
                            'on the way',
                            'delivered'
                        ]);
                        ?>

                        <p><strong>Donation By:</strong> <?= htmlspecialchars($rq['donor_first_name'] . ' ' . $rq['donor_last_name']) ?></p>
                        <p><strong>Requested Date:</strong> <?= isset($rq['requested_at']) ? date("M d, Y", strtotime($rq['requested_at'])) : '—' ?></p>

                        <p>
                            <strong>Delivery Status:</strong>
                            <span class="delivery-badge 
                                <?= $deliveryStatus == 'pending' ? 'ds-pending' :
                                ($deliveryStatus == 'waiting for pickup' ? 'ds-ready' :
                                ($deliveryStatus == 'at sorting facility' ? 'ds-sorting' :
                                ($deliveryStatus == 'on the way' ? 'ds-transit' :
                                ($deliveryStatus == 'delivered' ? 'ds-delivered' :
                                ($deliveryStatus == 'cancelled' ? 'ds-cancelled' : 'ds-pending'))))) ?>">
                                <?= htmlspecialchars(ucwords($rq['delivery_status'] ?? 'Pending')) ?>
                            </span>
                        </p>

                        <?php if ($showDeliveryDate): ?>
                            <p><strong>Delivery Date:</strong>
                                <?= !empty($rq['delivery_start'])
                                    ? date("M d, Y", strtotime($rq['delivery_start']))
                                    : '—'; ?>
                            </p>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>

                <div class="card-actions">
                    <?php if ($status === 'pending'): ?>
                        <button class="edit-request-btn" data-id="<?= htmlspecialchars($rq['request_id']); ?>">Edit Request</button>
                        <button class="cancel-request-btn" data-id="<?= htmlspecialchars($rq['request_id']); ?>">Cancel Request</button>

                    <?php else: ?>
                        <button class="view-details-btn" data-id="<?= htmlspecialchars($rq['request_id']); ?>">View Details</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-hand-paper"></i>
            <h3>No donation requests</h3>
            <p>You haven't requested any donations yet.</p>
        </div>
    <?php endif; ?>
</div>



                    <!-- Edit Request Modal -->
                    <div id="editRequestModal" class="modal" style="display:none;">
                    <div class="modal-content small-modal" style="max-width:520px; margin: 30px auto; border-radius: 8px; padding: 20px; position: relative;">
                        <h3 style="margin-top:0;">Edit Request</h3>
                        <form id="editRequestForm">
                        <input type="hidden" name="request_id" id="editRequestId" value="">
                        <div class="form-row">
                            <label for="editQuantityClaim">Quantity Claimed:</label>
                            <input type="number" 
                                id="editQuantityClaim" 
                                name="quantity_claim" 
                                value="<?= htmlspecialchars($rq['quantity_claim']) ?>" 
                                max="<?= htmlspecialchars($rq['total_quantity']) ?>" 
                                required 
                                style="width:100%; padding:8px; margin-bottom:12px;">
                        </div>
                        <div class="form-row">
                            <label for="editUrgencyLevel">Urgency Level:</label>
                            <select name="urgency_level" id="editUrgencyLevel" required style="width:100%; padding:8px; margin-bottom:16px;">
                            <option value="Low">Low</option>
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                            </select>
                        </div>

                        <div style="display:flex; gap:12px; justify-content:flex-start;">
                            <button id="saveRequestBtn" class="btn btn-primary" type="submit" style="padding:8px 18px; background:#2e8b57; color:#fff; border:none; border-radius:6px;">Save</button>
                            <button id="cancelEditBtn" type="button" class="btn btn-secondary" style="padding:8px 18px; background:#aaa; color:#fff; border:none; border-radius:6px;">Cancel</button>
                        </div>
                        </form>
                    </div>
                    </div>


                    <!-- View Details Modal -->
                    <div id="request-details-modal" class="modal" style="display:none;">
                    <div class="modal-content">
                        <span class="close">&times;</span>
                        <h3>Request Details</h3>
                        <div class="details-body">
                        <p><strong>Donation By:</strong> <span id="modal-donor-name"></span></p>
                        <p><strong>Requested By:</strong> <span id="modal-requester-name">You</span></p>
                        <p><strong>Project Name:</strong> <span id="modal-project-name"></span></p>
                        <p><strong>Type of Waste:</strong> <span id="modal-category"></span></p>
                        <p><strong>Quantity:</strong> <span id="modal-quantity"></span></p>
                        <p><strong>Urgency Level:</strong> <span id="modal-urgency"></span></p>
                        <p><strong>Request Date:</strong> <span id="modal-date"></span></p>
                        <p><strong>Delivery Date:</strong> <span id="modal-delivery-date"></span></p>
                        <p><strong>Delivery Status:</strong> <span id="modal-delivery-status"></span></p>
                        <div id="modal-donation-image" style="margin-top:10px;"></div>
                        </div>

                        <div class="modal-footer" style="margin-top:20px;">
                        <button id="modal-cancel-request" class="cancel-btn">Cancel Request</button>
                        <p id="cancel-warning" class="warning-text" style="display:none; color:gray; font-size:0.9em; margin-top:8px;">
                            Cannot cancel request. Delivery is in progress or is on its way.
                        </p>
                        </div>
                    </div>
                    </div>


                    <!-- Requests for My Donations Tab -->
                    <div id="requests-for-me" class="tab-content">
                        <?php if (!empty($requestsForMe)): ?>
                            <?php foreach ($requestsForMe as $r): ?>
                                <div class="donation-card">
                                    <div class="donation-header">
                                        <span class="donation-title"><?= htmlspecialchars($r['category']) ?></span>
                                        <span class="donation-status 
                                            <?= strtolower($r['request_status']) == 'pending' ? 'status-pending' :
                                            (strtolower($r['request_status']) == 'approved' ? 'status-completed' :
                                            (strtolower($r['request_status']) == 'declined' ? 'status-declined' :
                                            (strtolower($r['request_status']) == 'requested' ? 'status-requested' :
                                            (strtolower($r['request_status']) == 'received' ? 'status-received' : 'status-pending')))) ?>">
                                            <?= ucfirst(htmlspecialchars($r['request_status'])) ?>
                                        </span>


                                    </div>
                                    <div class="donation-details">
                                        <p><strong>Requested By:</strong> <?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></p>
                                        <p><strong>Project Name:</strong> <?= htmlspecialchars($r['project_name'] ?? '—') ?></p>
                                        <p><strong>Type of Waste:</strong> <?= htmlspecialchars($r['category'] ?? '—') ?></p>
                                        <p><strong>Quantity:</strong> 
                                            <?= htmlspecialchars($r['quantity_claim'] ?? $r['quantity'] ?? '—') ?>/<?= htmlspecialchars($r['total_quantity'] ?? $r['quantity'] ?? '—') ?>
                                        </p>
                                        <p><strong>Location:</strong> <?= htmlspecialchars($r['address'] ?? '—') ?></p>
                                        <p><strong>Requested At:</strong> <?= date("M d, Y H:i", strtotime($r['requested_at'])) ?></p>
                                        <p><strong>Urgency Level:</strong> <?= htmlspecialchars($r['urgency_level'] ?? 'Normal') ?></p>
                                        <p><strong>Status:</strong> <?= htmlspecialchars($r['request_status']) ?></p>
                                    </div>
                                    <div class="card-actions">
                                        <?php if (strtolower($r['request_status']) === 'pending'): ?>
                                            <button class="approve-request-btn" data-id="<?= $r['request_id'] ?>">Approve</button>
                                            <button class="decline-request-btn" data-id="<?= $r['request_id'] ?>">Decline</button>
                                        <?php elseif (strtolower($r['request_status']) === 'declined'): ?>
                                            <button class="delete-request-btn" data-id="<?= $r['request_id'] ?>">Delete</button>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <h3>No requests for your donations</h3>
                                <p>No one has requested your donations yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>



                    <!-- Received Donations Tab -->
                    <div id="received-donations" class="tab-content">
                        <?php if (!empty($receivedDonations)): ?>
                            <?php foreach ($receivedDonations as $rec): ?>
                                <div class="donation-card">
                                    <div class="donation-header">
                                        <span class="donation-title"><?= htmlspecialchars($rec['subcategory']) ?></span>
                                        <span class="donation-status status-completed">Received</span>
                                    </div>
                                    <div class="donation-details">
                                        <p class="donation-detail"><strong>Donor:</strong> <?= $rec['donor_name'] ?? '—' ?></p>
                                        <p class="donation-detail"><strong>Delivered At:</strong>
                                            <?= $rec['received_at'] ? date("M d, Y", strtotime($rec['received_at'])) : '—' ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-gift"></i>
                                <h3>No donations received yet</h3>
                                <p>You haven't received any donations yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Details Modal -->
<div id="details-modal">
    <div class="modal-content">
        <span class="close-btn">&times;</span>
        <div id="details-body"></div>
    </div>
</div>



    <div class="feedback-btn" id="feedbackBtn">💬</div>
<div class="feedback-modal" id="feedbackModal">
    <div class="feedback-content">
        <span class="feedback-close-btn" id="feedbackCloseBtn">&times;</span>
        <!-- changed from div to form -->
        <form class="feedback-form" id="feedbackForm">
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
        </form>
        <div class="thank-you-message" id="thankYouMessage">
            <span class="thank-you-emoji">🎉</span>
            <h3>Thank You!</h3>
            <p>We appreciate your feedback and will use it to improve EcoWaste.</p>
            <p>Your opinion matters to us!</p>
        </div>
    </div>
</div>

<!-- Load jQuery first -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    // ==================== PROFILE DROPDOWN TOGGLE ====================
    document.getElementById('userProfile').addEventListener('click', function() {
        this.classList.toggle('active');
    });
    document.addEventListener('click', function(event) {
        const userProfile = document.getElementById('userProfile');
        if (!userProfile.contains(event.target)) {
            userProfile.classList.remove('active');
        }
    });

    // ==================== TAB DESCRIPTIONS AND TAB SWITCHING ====================
    const descriptions = {
        "my-donations": "Here are all the donations you created. You can manage them here.",
        "requests-for-me": "These are requests from other users for your donations.",
        "my-requests": "These are the donation requests you have made to other donors.",
        "received-donations": "These are the donations you have successfully received."
    };

    function showTab(tabId, btn) {
        $(".tab-content").removeClass("tab-active");
        $(".tab-btn").removeClass("tab-btn-active");
        $("#" + tabId).addClass("tab-active");
        $(btn).addClass("tab-btn-active");
        $("#tab-description").text(descriptions[tabId]);
    }

    // ==================== DELETE DONATION ====================
    $(document).on("click", ".delete-btn", function() {
        if (confirm("Are you sure you want to delete this donation?")) {
            let id = $(this).data("id");
            $.post("donate_process.php", { action: "delete_donation", donation_id: id }, function() {
                location.reload();
            });
        }
    });

    // ==================== VIEW DONATION DETAILS (POPUP) ====================
    $(document).on("click", ".view-details", function(e) {
        e.preventDefault();
        let id = $(this).data("id");
        $.get("donate_process.php", { action: "view_donation", donation_id: id }, function(data) {
            $("#details-body").html(data);
            $("#details-modal").show();
        });
    });

    // ==================== FIXED EDIT REQUEST LOGIC ====================
    // Opens the edit modal with request data loaded from database
    $(document).on("click", ".edit-request-btn", function(e) {
        e.preventDefault();
        let id = $(this).data("id");

        if (!id) return Swal.fire({ icon: "error", title: "Error", text: "Invalid request ID" });

        $.get("get_request_data.php", { id: id }, function(response) {
            try {
                let res = typeof response === "object" ? response : JSON.parse(response);

                if (res.status === "success" && res.data) {
                    // Fill modal fields with current request data
                    $("#editRequestId").val(res.data.request_id || "");
                    $("#editQuantityClaim").val(res.data.quantity_claim || "");
                    $("#editUrgencyLevel").val(res.data.urgency_level || "");
                    $("#editRequestModal").fadeIn(200);
                } else {
                    Swal.fire({ icon: "error", title: "Error", text: res.message || "Unable to load request details." });
                }
            } catch (e) {
                console.error("Invalid JSON:", response, e);
                Swal.fire({ icon: "error", title: "Error", text: "Error loading request data." });
            }
        }).fail(function(jqXHR, textStatus, errorThrown){
            console.error("AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
            Swal.fire({ icon: "error", title: "Server Error", text: "Could not load request. Please try again." });
        });
    });

    // Closes the edit modal when "Cancel" is clicked
    $("#cancelEditBtn").on("click", function() {
        $("#editRequestModal").fadeOut(200);
    });

    // Submits the edited request to server
 $(document).on("submit", "#editRequestForm", function(e) {
    e.preventDefault();

    const maxQuantity = parseInt($("#editQuantityClaim").data("max"));
    const requestedQuantity = parseInt($("#editQuantityClaim").val());

    if (requestedQuantity > maxQuantity) {
        Swal.fire({
            icon: "error",
            title: "Invalid Quantity",
            text: `You cannot request more than ${maxQuantity} units.`
        });
        return;
    }

    // Continue with AJAX request
    const formData = $(this).serialize();

    $.post("edit_request_process.php", formData, function(response) {
        let res;
        try {
            res = typeof response === "object" ? response : JSON.parse(response);
        } catch (e) {
            Swal.fire({ icon: "error", title: "Server Error", text: "Could not update request." });
            return;
        }

        if (res.status === "success") {
            $("#editRequestModal").fadeOut(200);
            Swal.fire({
                icon: "success",
                title: "Updated!",
                text: res.message || "Request updated successfully.",
                timer: 1500,
                showConfirmButton: false
            });

            // Refresh "My Requests" tab smoothly
            $("#my-requests").load(location.href + " #my-requests > *");
        } else {
            Swal.fire({ icon: "error", title: "Update Failed", text: res.message || "Failed to update request." });
        }
    });
});

    // ==================== END EDIT REQUEST LOGIC ====================

    // ==================== CANCEL REQUEST ====================

$(document).on("click", ".cancel-request-btn", function() {
    const btn = $(this);
    const requestId = btn.data("id");

    if (!requestId) return Swal.fire({ icon: "error", title: "Error", text: "Invalid request ID" });
    if (!confirm("Are you sure you want to cancel this request?")) return;

    $.post("donate_process.php", { action: "cancel_request", request_id: requestId }, function(response) {
       // inside your existing $.post(..., function(response) { ... })
        try {
            const data = typeof response === "object" ? response : JSON.parse(response);

            if (data.status === "success") {
                Swal.fire({
                    icon: "success",
                    title: "Request Canceled",
                    text: "Your donation request has been canceled.",
                    timer: 1500,
                    showConfirmButton: false
                });

                // Smoothly remove the canceled request card
                const removedCard = btn.closest(".donation-card");
                removedCard.fadeOut(400, function() {
                    $(this).remove();

                    // --- NEW: if there are no more request cards in the My Requested tab, show empty state ---
                    const myRequestsContainer = $("#my-requests");
                    // If there are zero visible donation-card children, display the empty-state block.
                    if (myRequestsContainer.find(".donation-card").length === 0) {
                        // if an .empty-state template already exists elsewhere, clone it; otherwise insert minimal markup
                        let empty = myRequestsContainer.find(".empty-state").first();
                        if (empty.length === 0) {
                            // insert a basic empty state matching the markup used elsewhere:
                            myRequestsContainer.append(
                                `<div class="empty-state">
                                    <i class="fas fa-hand-paper"></i>
                                    <h3>No donation requests</h3>
                                    <p>You haven't requested any donations yet.</p>
                                </div>`
                            );
                        } else {
                            empty.show();
                        }
                    }
                });

                // Update the homepage/browse quantity if provided
                if (data.updated_donation_id !== undefined && data.new_quantity !== undefined) {
                    const donationCard = $(`.donation-card[data-id='${data.updated_donation_id}']`);
                    if (donationCard.length) {
                        donationCard.find(".donation-detail:contains('Quantity'), .info-item:contains('Quantity')").first()
                            .html('<strong>Quantity:</strong> ' + data.new_quantity + '/' + data.total_quantity + ' Units');
                    }
                }
            } else {
                Swal.fire({ icon: "error", title: "Failed", text: data.message || "Could not cancel request." });
            }
        } catch (e) {
            Swal.fire({ icon: "error", title: "Error", text: "Invalid server response." });
            console.error("Invalid JSON:", response, e);
        }

    });
});

// ===================== VIEW REQUEST DETAILS MODAL ====================
$(document).on('click', '.view-details-btn', function() {
  const requestId = $(this).data('id');

  $.ajax({
    url: 'get_request_details.php',
    method: 'GET',
    data: { id: requestId },
    dataType: 'json',

    success: function(res) {
      if (res.status === 'success' && res.data) {
        const d = res.data;

        // Populate modal fields
        $('#modal-donor-name').text(d.donor_name || 'Unknown');
        $('#modal-project-name').text(d.project_name || '—');
        $('#modal-category').text((d.category || '') + (d.subcategory ? ' → ' + d.subcategory : ''));
        $('#modal-quantity').text(d.quantity_claim || '—');
        $('#modal-urgency').text(d.urgency_level || '—');
        $('#modal-date').text(d.requested_at ? new Date(d.requested_at).toLocaleString() : '—');

        
        // --- DELIVERY STATUS & DATE HANDLING ---
        const deliveryStatus = (d.delivery_status || '').toLowerCase();
        const showDeliveryDate = ['waiting for pickup', 'at sorting facility', 'on the way', 'delivered'].includes(deliveryStatus);

        // 🟩 Display Delivery Status (with color coding)
        if (deliveryStatus) {
        const formattedStatus = d.delivery_status.replace(/\b\w/g, c => c.toUpperCase());
        $('#modal-delivery-status')
            .text(formattedStatus)
            .css({
            padding: '4px 10px',
            borderRadius: '6px',
            color: '#fff',
            fontWeight: '600',
            display: 'inline-block',
            backgroundColor:
                deliveryStatus === 'waiting for pickup' ? '#ff9800' :
                deliveryStatus === 'at sorting facility' ? '#9c27b0' :
                deliveryStatus === 'on the way' ? '#2196f3' :
                deliveryStatus === 'delivered' ? '#4caf50' :
                deliveryStatus === 'cancelled' ? '#f44336' : '#9e9e9e'
            })
            .show();
        } else {
        $('#modal-delivery-status').text('Pending').css({
            backgroundColor: '#9e9e9e',
            color: '#fff',
            padding: '4px 10px',
            borderRadius: '6px'
        });
        }

        // 🟨 Display Delivery Date only when allowed
        if (showDeliveryDate && d.delivery_start) {
        const start = new Date(d.delivery_start).toLocaleDateString('en-US', { month: 'long', day: 'numeric' });
        let displayDate = start;

        if (d.delivery_end) {
            const end = new Date(d.delivery_end).toLocaleDateString('en-US', { month: 'long', day: 'numeric' });
            displayDate = `${start} - ${end}`;
        }

        $('#modal-delivery-date').text(displayDate);
        $('#modal-delivery-date').closest('p').show();
        } else {
        // Show "Pending" if no date yet and status < eligible
        $('#modal-delivery-date').text('Pending');
        $('#modal-delivery-date').closest('p').toggle(showDeliveryDate);
        }




        // Handle image display — consistent with "My Donations"
        let imageUrl = '';
        if (d.image_path) {
          imageUrl = d.image_path;
        } else if (d.image) {
          imageUrl = `uploads/${d.image}`;
        } else if (d.donation_image) {
          imageUrl = `uploads/${d.donation_image}`;
        }

        $('#modal-donation-image').html(
          imageUrl
            ? `<img src="${imageUrl}" alt="Donation image" style="width:100%;max-height:250px;border-radius:8px;">`
            : '<em>No image available</em>'
        );

        // ✅ Cancel Button Logic + Styling
        const deliveryPending = !d.delivery_start && !d.delivery_end;

        if (deliveryPending) {
          // Pending → orange and active
          $('#modal-cancel-request')
            .prop('disabled', false)
            .css({
              backgroundColor: '#ff9800',
              color: '#fff',
              cursor: 'pointer',
              opacity: 1,
              transition: '0.3s'
            })
            .hover(
              function() { $(this).css('backgroundColor', '#e68900'); },
              function() { $(this).css('backgroundColor', '#ff9800'); }
            );
          $('#cancel-warning').hide();
        } else {
          // Delivery set → gray and disabled
          $('#modal-cancel-request')
            .prop('disabled', true)
            .css({
              backgroundColor: '#b0b0b0',
              color: '#fff',
              cursor: 'not-allowed',
              opacity: 0.8
            })
            .off('mouseenter mouseleave');
          $('#cancel-warning')
            .text('Cannot cancel request. Delivery schedule has been set by the donor.')
            .show();
        }

        // Store request ID for cancel logic
        $('#modal-cancel-request').data('request-id', requestId);

        // Open modal
        $('#request-details-modal').fadeIn(200);
      } else {
        alert(res.message || 'Unable to fetch request details.');
      }
    },
    error: function() {
      alert('Error fetching details. Please try again.');
    }
  });
});

// Close modal on X click or outside click
$(document).on('click', '.modal .close', function() {
  $('#request-details-modal').fadeOut(200);
});
$(window).on('click', function(e) {
  if ($(e.target).is('#request-details-modal')) {
    $('#request-details-modal').fadeOut(200);
  }
});


// ==================== CANCEL REQUEST INSIDE MODAL (SweetAlert Version) ====================
$(document).on('click', '#modal-cancel-request', function () {
  const requestId = $(this).data('request-id');
  if (!requestId) return;

  Swal.fire({
    title: 'Cancel this request?',
    text: 'Once cancelled, this request cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, cancel it',
    cancelButtonText: 'No, keep it',
    reverseButtons: true,
  }).then((result) => {
    if (result.isConfirmed) {
      $.post(
        'donate_process.php',
        { action: 'cancel_request', request_id: requestId },
        function (response) {
          let res = typeof response === 'object' ? response : JSON.parse(response || '{}');

          if (res.status === 'success') {
            Swal.fire({
              icon: 'success',
              title: 'Cancelled!',
              text: res.message || 'Your request has been cancelled successfully.',
              timer: 1500,
              showConfirmButton: false,
            });

            $('#request-details-modal').fadeOut(200);

            // Remove canceled card dynamically
            $(`.request-card[data-request-id="${requestId}"]`).remove();

            // If no requests left, show empty state
            if ($('#my-requests .request-card').length === 0) {
              $('#my-requests').html(`
                <div class="empty-state">
                  <i class="fas fa-hand-paper"></i>
                  <h3>No donation requests</h3>
                  <p>You haven't requested any donations yet.</p>
                </div>
              `);
            }
          } else {
            Swal.fire({
              icon: 'error',
              title: 'Failed',
              text: res.message || 'Unable to cancel the request.',
            });
          }
        },
        'json'
      ).fail(() => {
        Swal.fire({
          icon: 'error',
          title: 'Server Error',
          text: 'Could not cancel the request. Please try again later.',
        });
      });
    }
  });
});



    // ==================== CLOSE DONATION DETAILS MODAL ====================
    $(document).on("click", ".close-btn", function() {
        $("#details-modal").hide();
    });
    $(window).on("click", function(e) {
        if (e.target === document.getElementById("details-modal")) {
            $("#details-modal").hide();
        }
    });

    // ==================== ADD COMMENT (DONATION COMMENT SECTION) ====================
    $(document).on("submit", ".add-comment-form", function(e) {
        e.preventDefault();
        let form = $(this);
        let donationId = form.data("id");
        let commentText = form.find("textarea[name='comment_text']").val();

        $.post("donate_process.php", {
            action: "add_comment",
            donation_id: donationId,
            comment_text: commentText
        }, function(response) {
            try {
                let res = JSON.parse(response);
                if (res.status === "success") {
                    // Reload comments after adding one
                    $.get("donate_process.php", { action: "view_donation", donation_id: donationId }, function(data) {
                        $("#details-body").html(data);
                    });
                } else {
                    alert(res.message);
                }
            } catch (e) {
                console.error("Invalid response:", response);
            }
        });
    });

    // ==================== FEEDBACK MODAL SYSTEM ====================
    $(document).ready(function() {
        const feedbackBtn = $("#feedbackBtn");
        const feedbackModal = $("#feedbackModal");
        const feedbackCloseBtn = $("#feedbackCloseBtn");
        const emojiOptions = $(".emoji-option");
        const feedbackForm = $("#feedbackForm");
        const thankYouMessage = $("#thankYouMessage");
        const feedbackSubmitBtn = $("#feedbackSubmitBtn");
        const spinner = $("#spinner");
        const ratingError = $("#ratingError");
        const textError = $("#textError");
        const feedbackText = $("#feedbackText");

        let selectedRating = 0;

        // Emoji click event (rating selection)
        emojiOptions.on("click", function() {
            emojiOptions.removeClass("selected");
            $(this).addClass("selected");
            selectedRating = $(this).data("rating");
            ratingError.hide();
        });

        // Feedback form submit
        feedbackForm.on("submit", function(e) {
            e.preventDefault();
            let isValid = true;

            if (selectedRating === 0) {
                ratingError.show();
                isValid = false;
            } else ratingError.hide();

            if ($.trim(feedbackText.val()) === "") {
                textError.show();
                isValid = false;
            } else textError.hide();

            if (!isValid) return;

            feedbackSubmitBtn.prop("disabled", true);
            spinner.show();

            // Send feedback to server
            $.post("feedback_process.php", { 
                rating: selectedRating, 
                feedback: feedbackText.val().trim() 
            }, function(response){
                try {
                    let res = JSON.parse(response);
                    if(res.status === "success"){
                        spinner.hide();
                        feedbackForm.hide();
                        thankYouMessage.show();

                        setTimeout(() => {
                            feedbackModal.hide();
                            feedbackForm.show();
                            thankYouMessage.hide();
                            feedbackText.val("");
                            emojiOptions.removeClass("selected");
                            selectedRating = 0;
                            feedbackSubmitBtn.prop("disabled", false);
                        }, 3000);
                    } else {
                        alert(res.message || "Something went wrong.");
                        spinner.hide();
                        feedbackSubmitBtn.prop("disabled", false);
                    }
                } catch(e) {
                    console.error("Invalid JSON response:", response);
                    spinner.hide();
                    feedbackSubmitBtn.prop("disabled", false);
                }
            });
        });

        // Feedback modal open/close
        feedbackBtn.on("click", () => feedbackModal.css("display", "flex"));
        feedbackCloseBtn.on("click", closeFeedbackModal);
        $(window).on("click", function(event) {
            if (event.target === feedbackModal[0]) {
                closeFeedbackModal();
            }
        });

        function closeFeedbackModal() {
            feedbackModal.hide();
            feedbackForm.show();
            thankYouMessage.hide();
            feedbackText.val("");
            emojiOptions.removeClass("selected");
            selectedRating = 0;
            ratingError.hide();
            textError.hide();
            feedbackSubmitBtn.prop("disabled", false);
            spinner.hide();
        }
    });

    // ==================== APPROVE / DECLINE REQUEST LOGIC ====================
    (function(){
        function postAction(action, requestId) {
            return $.ajax({
                url: 'donate_process.php',
                method: 'POST',
                data: { action: action, request_id: requestId },
                dataType: 'json',
                timeout: 10000
            }).catch(err => {
                console.error("AJAX error", action, requestId, err);
                return { status: 'error', message: 'Network/server error.' };
            });
        }

        $(document).on('click', '.approve-request-btn, .approve-btn, .decline-request-btn, .decline-btn', async function(e) {
            e.preventDefault();
            const btn = $(this);
            const isApprove = btn.hasClass('approve-request-btn') || btn.hasClass('approve-btn');
            const action = isApprove ? 'approve_request' : 'decline_request';

            const container = btn.closest('.request, .donation-card, [data-request-id], [data-id]');
            const requestId = container.data('request-id') || container.data('id') || btn.data('id') || btn.data('request-id');

            if (!requestId) return alert("Request ID missing.");

            if (!confirm((isApprove ? "Approve" : "Decline") + " this donation request?")) return;

            btn.prop('disabled', true).text(isApprove ? 'Approving...' : 'Declining...');

            try {
                const res = await postAction(action, requestId);

                if (res.status === 'success') {
                    Swal.fire({
                        icon: "success",
                        title: isApprove ? "Approved!" : "Declined!",
                        text: res.message || `Request ${isApprove ? 'approved' : 'declined'} successfully.`,
                        timer: 1500,
                        showConfirmButton: false,
                        willClose: () => {
                            // Smooth refresh of "My Requests" tab
                            $("#my-requests").load(location.href + " #my-requests > *");
                        }
                    });
                } else {
                    Swal.fire({
                        icon: "error",
                        title: "Error",
                        text: res.message || "Server returned an error."
                    });
                    btn.prop('disabled', false).text(isApprove ? 'Approve' : 'Decline');
                }
            } catch (err) {
                console.error("AJAX error:", err);
                Swal.fire({
                    icon: "error",
                    title: "Server Error",
                    text: "Something went wrong. Please try again."
                });
                btn.prop('disabled', false).text(isApprove ? 'Approve' : 'Decline');
            }
        });
    })();

    // ==================== DELETE REQUEST (USER SIDE) ====================
    document.querySelectorAll('.delete-request-btn').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.getAttribute('data-id');
            const card = this.closest('.donation-card');

            if (!confirm("Are you sure you want to delete this request? This cannot be undone.")) return;

            fetch('delete_request.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ request_id: requestId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    card.style.transition = 'opacity 0.4s ease, transform 0.3s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.95)';
                    setTimeout(() => card.remove(), 400);
                } else {
                    alert('Failed to delete request.');
                }
            })
            .catch(err => alert('Error: ' + err.message));
        });
    });
</script>


<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


</body>
</html>