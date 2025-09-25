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
$stmt = $conn->prepare("SELECT * FROM donations WHERE donor_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $myDonations[] = $row;

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
    SELECT dr.*, d.item_name, u.first_name, u.last_name, d.status, p.project_name
    FROM donation_requests dr
    JOIN donations d ON dr.donation_id = d.donation_id
    JOIN users u ON dr.user_id = u.user_id
    LEFT JOIN projects p ON dr.project_id = p.project_id
    WHERE d.donor_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $requestsForMe[] = $row;

// Fetch My Requested Donations (requests I made to others)
$myRequests = [];
$stmt = $conn->prepare("
    SELECT dr.*, 
           d.item_name, 
           d.status,
           u.first_name AS donor_first_name,
           u.last_name AS donor_last_name,
           p.project_name
    FROM donation_requests dr
    JOIN donations d ON dr.donation_id = d.donation_id
    JOIN users u ON d.donor_id = u.user_id
    LEFT JOIN projects p ON dr.project_id = p.project_id
    WHERE dr.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $myRequests[] = $row;

// Fetch My Received Donations
$receivedDonations = [];
$stmt = $conn->prepare("SELECT * FROM donations WHERE receiver_id = ?");
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
    <style>
.profile-pic {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    overflow: hidden;
    background-color: #3d6a06ff;
    color: white;
    font-weight: bold;
    font-size: 18px;
}
.donations-container {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    padding: 20px;
}

.tab-content-wrapper {
    margin-top: 20px;
}


/* Limit donation description text */
.donation-detail.description {
    max-height: 60px;       /* limit visible height */
    overflow: hidden;       /* hide excess text */
    text-overflow: ellipsis; 
    display: -webkit-box;
    -webkit-line-clamp: 3;  /* number of lines to show */
    -webkit-box-orient: vertical;
    white-space: normal;
}


/* Fix donation cards to prevent exceeding container */
.donations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.donation-card {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    padding: 20px;
    margin-bottom: 0; /* Remove margin-bottom since grid gap handles spacing */
    border-left: 4px solid #82AA52;
    height: fit-content; /* Let content determine height */
    min-height: 200px; /* Minimum height for consistency */
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.donation-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.donation-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
    flex-wrap: wrap;
    gap: 10px;
}

.donation-title {
    font-weight: 700;
    font-size: 18px;
    color: #2e8b57;
    word-break: break-word; /* Prevent long titles from breaking layout */
}

.donation-status {
    font-size: 12px;
    padding: 6px 12px;
    border-radius: 20px;
    color: white;
    font-weight: 600;
    white-space: nowrap; /* Prevent status text from wrapping */
}

.status-completed {
    background-color: #82AA52;
}

.status-pending {
    background-color: #F59E0B;
}

.status-received {
    background-color: #3B82F6;
}

.status-requested {
    background-color: #8B5CF6;
}

.donation-details {
    margin-bottom: 20px;
    flex-grow: 1; /* Allow details to take available space */
}

.donation-detail {
    margin-bottom: 8px;
    font-size: 14px;
    color: #555;
    display: flex;
    flex-wrap: wrap;
}

.donation-detail strong {
    min-width: 120px;
    color: #333;
}

.donation-requests-list {
    margin-left: 20px;
    margin-top: 5px;
    max-height: 150px; /* Limit height and add scroll if needed */
    overflow-y: auto;
}

.donation-requests-list li {
    margin-bottom: 5px;
    font-size: 13px;
    color: #666;
    padding: 3px 0;
    word-break: break-word;
}

.card-actions {
    display: flex;
    gap: 10px;
    margin-top: auto; /* Push actions to bottom */
    padding-top: 15px;
    border-top: 1px solid #f0f0f0;
    flex-wrap: wrap;
}

.view-details {
    color: #2e8b57;
    font-weight: 600;
    text-decoration: none;
    font-size: 14px;
    display: inline-block;
    padding: 8px 16px;
    border: 1px solid #2e8b57;
    border-radius: 4px;
    transition: all 0.3s;
    text-align: center;
    flex: 1;
    min-width: 120px;
}

.view-details:hover {
    background-color: #2e8b57;
    color: white;
}

.delete-btn, .edit-request-btn, .cancel-request-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s;
    text-align: center;
    flex: 1;
    min-width: 120px;
}

.delete-btn {
    background-color: #e74c3c;
    color: white;
}

.delete-btn:hover {
    background-color: #c0392b;
}

.edit-request-btn {
    background-color: #3498db;
    color: white;
}

.edit-request-btn:hover {
    background-color: #2980b9;
}

.cancel-request-btn {
    background-color: #f39c12;
    color: white;
}

.cancel-request-btn:hover {
    background-color: #d35400;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #666;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    grid-column: 1 / -1;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    color: #ddd;
}

.empty-state h3 {
    margin-bottom: 10px;
    color: #777;
}

/* Responsive fixes */
@media (max-width: 768px) {
    .donations-grid {
        grid-template-columns: 1fr;
    }
    
    .donation-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .donation-detail strong {
        min-width: 100px;
    }
    
    .card-actions {
        flex-direction: column;
    }
    
    .card-actions a, .card-actions button {
        width: 100%;
        min-width: auto;
    }
    
    .donation-requests-list {
        max-height: 100px;
    }
}

/* Ensure tabs container doesn't exceed */
.tabs-container {
    margin-bottom: 20px;
    width: 100%;
    overflow: hidden;
    position: sticky;
    top: 0; /* sticks right below header */
    background-color: #fff;
    z-index: 10;
    padding-top: 10px;
}

/* Scrollable content area like sidebar */
.tab-content-wrapper {
    max-height: calc(100vh - 250px); /* adjust based on header+tabs height */
    overflow-y: auto;
    padding-right: 10px;
}

/* Match sidebar style for scrollbar */
.tab-content-wrapper::-webkit-scrollbar {
    width: 8px;
}
.tab-content-wrapper::-webkit-scrollbar-track {
    background: #f6ffeb;
    border-radius: 10px;
}
.tab-content-wrapper::-webkit-scrollbar-thumb {
    background: #82AA52;
    border-radius: 10px;
}
.tab-content-wrapper::-webkit-scrollbar-thumb:hover {
    background: #6d8f45;
}


.tabs {
    display: flex;
    border-bottom: 1px solid #e0e0e0;
    overflow-x: auto;
    width: 100%;
}

.tab-btn {
    padding: 12px 24px;
    cursor: pointer;
    font-weight: 600;
    color: #666;
    border-bottom: 3px solid transparent;
    transition: all 0.3s;
    white-space: nowrap;
    flex-shrink: 0; /* Prevent tabs from shrinking */
}

.tab-btn:hover {
    color: #2e8b57;
}

.tab-btn-active {
    color: #2e8b57;
    border-bottom: 3px solid #2e8b57;
}

.tab-content {
    display: none;
    width: 100%;
}

.tab-active {
    display: block;
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
                <?= strtoupper(substr(htmlspecialchars($_SESSION['first_name'] ?? 'User'), 0, 1)) ?>
            </div>
            <span class="profile-name"><?= htmlspecialchars($_SESSION['first_name'] ?? 'User') ?></span>
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
                    <li><a href="donations.php" style="color: #2e8b57;"><i class="fas fa-box"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>
        
        <main class="main-content">
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
                        <span class="donation-title"><?= htmlspecialchars($d['item_name']) ?></span>
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
                        <div class="donation-card">
                            <div class="donation-header">
                                <span class="donation-title"><?= htmlspecialchars($rq['item_name']) ?></span>
                                <span class="donation-status 
                                    <?= strtolower($rq['status']) == 'pending' ? 'status-pending' :
                                       (strtolower($rq['status']) == 'completed' ? 'status-completed' :
                                       (strtolower($rq['status']) == 'requested' ? 'status-received' : '')) ?>">
                                    <?= htmlspecialchars($rq['status']) ?>
                                </span>
                            </div>
                            <div class="donation-details">
                                <p class="donation-detail"><strong>Donation by:</strong> 
                                <?= htmlspecialchars($rq['donor_first_name'] . ' ' . $rq['donor_last_name']) ?></p>
                                <p class="donation-detail"><strong>Project:</strong> <?= htmlspecialchars($rq['project_name']) ?></p>
                                <p class="donation-detail"><strong>Request Date:</strong> <?= date("M d, Y H:i", strtotime($rq['requested_at'])) ?></p>
                            </div>
                            <div class="card-actions">
                                <button class="edit-request-btn" data-id="<?= $rq['request_id'] ?>">Edit Request</button>
                                <button class="cancel-request-btn" data-id="<?= $rq['request_id'] ?>">Cancel Request</button>
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

            <!-- Requests for My Donations Tab -->
            <div id="requests-for-me" class="tab-content">
                <?php if (!empty($requestsForMe)): ?>
                    <?php foreach ($requestsForMe as $r): ?>
                        <div class="donation-card">
                            <div class="donation-header">
                                <span class="donation-title"><?= htmlspecialchars($r['item_name']) ?></span>
                                <span class="donation-status status-pending">Pending</span>
                            </div>
                            <div class="donation-details">
                                <p class="donation-detail"><strong>Requested by:</strong> <?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></p>
                                <p class="donation-detail"><strong>Project:</strong> <?= htmlspecialchars($r['project_name']) ?></p>
                                <p class="donation-detail"><strong>Requested At:</strong> <?= date("M d, Y H:i", strtotime($r['requested_at'])) ?></p>
                            </div>
                            <div class="card-actions">
                                <button class="edit-request-btn" data-id="<?= $r['request_id'] ?>">Approve</button>
                                <button class="cancel-request-btn" data-id="<?= $r['request_id'] ?>">Decline</button>
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
                                <span class="donation-title"><?= htmlspecialchars($rec['item_name']) ?></span>
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
        </main>
    </div>
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

        const descriptions = {
            "my-donations": "Here are all the donations you created. You can manage them here.",
            "requests-for-me": "These are requests from other users for your donations.",
            "my-requests": "These are the donation requests you have made to other donors.",
            "received-donations": "These are the donations you have successfully received."
        };

        function showTab(tabId, btn) {
            document.querySelectorAll(".tab-content").forEach(tc => tc.classList.remove("tab-active"));
            document.getElementById(tabId).classList.add("tab-active");

            document.querySelectorAll(".tab-btn").forEach(tb => tb.classList.remove("tab-btn-active"));
            btn.classList.add("tab-btn-active");

            document.getElementById("tab-description").textContent = descriptions[tabId];
        }

        // Delete donation
        $(document).on("click", ".delete-btn", function(){
            if(confirm("Are you sure you want to delete this donation?")) {
                let id = $(this).data("id");
                $.post("donate_process.php", { action: "delete_donation", donation_id: id }, function(){
                    location.reload();
                });
            }
        });

        // View details
        $(document).on("click", ".view-details", function(e){
            e.preventDefault();
            let id = $(this).data("id");
            $.get("donate_process.php", { action: "view_donation", donation_id: id }, function(data){
                $("#details-body").html(data);
                $("#details-modal").show();
            });
        });

        // Cancel request
        $(document).on("click", ".cancel-request-btn", function(){
            if(confirm("Cancel this request?")) {
                let id = $(this).data("id");
                $.post("donate_process.php", { action: "cancel_request", request_id: id }, function(){
                    location.reload();
                });
            }
        });

        // Edit request (redirect or modal)
        $(document).on("click", ".edit-request-btn", function(){
            let id = $(this).data("id");
            window.location.href = "edit_request.php?id=" + id;
        });
            
        function showTab(tabId, clickedBtn) {
            const contents = document.querySelectorAll('.tab-content');
            const buttons = document.querySelectorAll('.tab-btn');
            contents.forEach(c => c.classList.remove('tab-active'));
            buttons.forEach(b => b.classList.remove('tab-btn-active'));
            document.getElementById(tabId).classList.add('tab-active');
            clickedBtn.classList.add('tab-btn-active');
        }
        
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