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
    SELECT 
        dr.request_id,
        dr.status AS request_status,
        dr.requested_quantity,
        dr.urgency_level,
        dr.requested_at,
        d.item_name,
        d.category,
        d.quantity AS total_quantity,
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
        dr.requested_quantity,
        dr.urgency_level,
        dr.requested_at,
        d.item_name, 
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
                    <li><a href="donations.php" class="active"><i class="fas fa-hand-holding-heart"></i>Donations</a></li>
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
                                        <p><strong>Donation By:</strong> <?= htmlspecialchars($rq['donor_first_name'] . ' ' . $rq['donor_last_name']) ?></p>
                                        <p><strong>Project Name:</strong> <?= htmlspecialchars($rq['project_name'] ?? '—') ?></p>
                                        <p><strong>Type of Waste:</strong> <?= htmlspecialchars($rq['category'] ?? '—') ?></p>
                                       <p><strong>Quantity:</strong> 
                                            <?= htmlspecialchars($rq['requested_quantity'] ?? 0) ?> /
                                            <?= htmlspecialchars($rq['total_quantity'] ?? 0) ?> Units
                                        </p>
                                        <p><strong>Request Date:</strong> <?= date("M d, Y H:i", strtotime($rq['requested_at'])) ?></p>
                                        <p><strong>Urgency Level:</strong> <?= htmlspecialchars($rq['urgency_level'] ?? 'Normal') ?></p>
                                        <p><strong>Status:</strong> <?= htmlspecialchars($rq['status']) ?></p>
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
                                            <?= htmlspecialchars($r['requested_quantity'] ?? $r['quantity'] ?? '—') ?>/<?= htmlspecialchars($r['total_quantity'] ?? $r['quantity'] ?? '—') ?>
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
    // Profile dropdown toggle
    document.getElementById('userProfile').addEventListener('click', function() {
        this.classList.toggle('active');
    });
    document.addEventListener('click', function(event) {
        const userProfile = document.getElementById('userProfile');
        if (!userProfile.contains(event.target)) {
            userProfile.classList.remove('active');
        }
    });

    // Tab descriptions
    const descriptions = {
        "my-donations": "Here are all the donations you created. You can manage them here.",
        "requests-for-me": "These are requests from other users for your donations.",
        "my-requests": "These are the donation requests you have made to other donors.",
        "received-donations": "These are the donations you have successfully received."
    };

    // Show tab
    function showTab(tabId, btn) {
        $(".tab-content").removeClass("tab-active");
        $(".tab-btn").removeClass("tab-btn-active");
        $("#" + tabId).addClass("tab-active");
        $(btn).addClass("tab-btn-active");
        $("#tab-description").text(descriptions[tabId]);
    }

    // Delete donation
    $(document).on("click", ".delete-btn", function() {
        if (confirm("Are you sure you want to delete this donation?")) {
            let id = $(this).data("id");
            $.post("donate_process.php", { action: "delete_donation", donation_id: id }, function() {
                location.reload();
            });
        }
    });

    // View details
    $(document).on("click", ".view-details", function(e) {
        e.preventDefault();
        let id = $(this).data("id");
        $.get("donate_process.php", { action: "view_donation", donation_id: id }, function(data) {
            $("#details-body").html(data);
            $("#details-modal").show();
        });
    });

    // Cancel request
    $(document).on("click", ".cancel-request-btn", function() {
        if (confirm("Cancel this request?")) {
            let id = $(this).data("id");
            $.post("donate_process.php", { action: "cancel_request", request_id: id }, function() {
                location.reload();
            });
        }
    });




    // Edit request
    $(document).on("click", ".edit-request-btn", function() {
        let id = $(this).data("id");
        window.location.href = "edit_request.php?id=" + id;
    });

    // Close details modal
    $(document).on("click", ".close-btn", function() {
        $("#details-modal").hide();
    });
    $(window).on("click", function(e) {
        if (e.target === document.getElementById("details-modal")) {
            $("#details-modal").hide();
        }
    });

    // Handle add comment
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
                // Reload details to show new comment
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

    // Feedback modal
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

        // Emoji select
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
    } else {
        ratingError.hide();
    }

    if ($.trim(feedbackText.val()) === "") {
        textError.show();
        isValid = false;
    } else {
        textError.hide();
    }

    if (!isValid) return;

    feedbackSubmitBtn.prop("disabled", true);
    spinner.show();

    // ✅ Send feedback to PHP
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
        
        // Open modal
        feedbackBtn.on("click", () => feedbackModal.css("display", "flex"));

        // Close modal
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

/* Robust approve/decline handler — paste near bottom, after jQuery */
(function(){
    // utility to send request and parse JSON safely
    function postAction(action, requestId) {
        return $.ajax({
            url: 'donate_process.php?action=' + action,
            method: 'POST',
            data: { request_id: requestId },
            dataType: 'text', // get raw text first so we can handle non-json responses
            timeout: 10000
        }).then(function(rawResponse) {
            // attempt to parse JSON
            try {
                return JSON.parse(rawResponse);
            } catch (e) {
                console.error("Non-JSON response for", action, requestId, "raw:", rawResponse);
                return { status: 'error', message: 'Invalid server response. Check PHP error log.' };
            }
        }, function(jqXHR, textStatus, errorThrown) {
            console.error("AJAX error", action, requestId, textStatus, errorThrown, jqXHR.responseText);
            return { status: 'error', message: 'Network or server error: ' + textStatus };
        });
    }

    $(document).on('click', '.approve-request-btn, .approve-btn, .decline-request-btn, .decline-btn', async function(e) {
        e.preventDefault();
        const btn = $(this);
        const isApprove = btn.hasClass('approve-request-btn') || btn.hasClass('approve-btn');
        const action = isApprove ? 'approve_request' : 'decline_request';

        // find the closest request container and its id
        const container = btn.closest('[data-id], [data-request-id], .request, .donation-card');
        // check multiple attribute possibilities
        const requestId = container.data('id') || container.data('request-id') || btn.data('id') || btn.data('request-id');

        if (!requestId) {
            console.error("Request ID not found on element or container:", btn, container);
            alert("Internal error: request id missing. See console.");
            return;
        }

        if (!confirm((isApprove ? "Approve" : "Decline") + " this donation request?")) return;

        // disable while processing
        btn.prop('disabled', true).text(isApprove ? 'Approving...' : 'Declining...');

        const res = await postAction(action, requestId);

        if (res && res.status === 'success') {
            // update UI in-place
            // find status text span in this request block
            let statusSpan = container.find('.status-text span').first();
            if (!statusSpan.length) {
                // fallback: find any .donation-status inside block
                statusSpan = container.find('.donation-status').first();
            }
            if (statusSpan.length) {
                statusSpan.text(isApprove ? 'approved' : 'declined');
            }

            // visually disable or change buttons
            container.find('button').prop('disabled', true);
            btn.text(isApprove ? 'Approved ✓' : 'Declined ✕');

            // update quantity display if server sent it
            if (res.new_quantity !== undefined && res.total_quantity !== undefined) {
                const qtyElem = container.closest('.donation-card').find('.donation-detail:contains("Quantity"), .info-item:contains("Quantity")').first();
                if (qtyElem.length) {
                    // replace numeric portion by simple string (safe)
                    qtyElem.html('<strong>Quantity:</strong> ' + res.new_quantity + '/' + res.total_quantity + ' Units');
                }
            }

            console.log("Action success:", res);
        } else {
            alert(res && res.message ? res.message : "Server returned an error. Check console.");
            console.error("Action failed:", res);
            btn.prop('disabled', false);
            // restore text
            btn.text(isApprove ? 'Approve' : 'Decline');
        }
    });
})();

// Handle Delete Request
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
                // Smooth fade out and remove
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.approve-request-btn, .decline-request-btn').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.getAttribute('data-id');
            const newStatus = this.classList.contains('approve-request-btn') ? 'approved' : 'declined';
            const card = this.closest('.donation-card');
            const statusSpan = card.querySelector('.donation-status');
            const actionsDiv = card.querySelector('.card-actions');

            // Disable buttons while loading
            this.disabled = true;
            actionsDiv.querySelectorAll('button').forEach(btn => btn.disabled = true);

            fetch('update_request_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ request_id: requestId, status: newStatus })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Update status text and color instantly
                    statusSpan.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                    statusSpan.className = 'donation-status ' + 
                        (newStatus === 'approved' ? 'status-completed' : 'status-declined');

                    // Replace buttons with message
                    actionsDiv.innerHTML = `<span class="action-note">${statusSpan.textContent} request</span>`;
                } else {
                    alert('Failed to update status: ' + (data.message || 'Please try again.'));
                    actionsDiv.querySelectorAll('button').forEach(btn => btn.disabled = false);
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
                actionsDiv.querySelectorAll('button').forEach(btn => btn.disabled = false);
            });
        });
    });
});
</script>

</body>
</html>