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
        dr.delivery_status,
        dr.quantity_claim,
        dr.urgency_level,
        dr.requested_at,
        dr.delivery_start,
        dr.delivery_end,
        d.subcategory,
        d.category,
        d.item_name,
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
                                        <span class="donation-status 
                                            <?= $status == 'pending' ? 'status-pending' :
                                            ($status == 'approved' ? 'status-approved' :
                                            ($status == 'requested' ? 'status-received' : '')) ?>">
                                            <?= htmlspecialchars(ucwords($rq['status'])) ?>
                                        </span>
                                    </div>

                                    <div class="donation-details">
                                        <p><strong>Donation By:</strong> <?= htmlspecialchars($rq['donor_first_name'] . ' ' . $rq['donor_last_name']) ?></p>
                                        <p><strong>Project Name:</strong> <?= htmlspecialchars($rq['project_name'] ?? '—') ?></p>
                                        <p><strong>Type of Waste:</strong> <?= htmlspecialchars($rq['category'] ?? '—') ?></p>
                                        <p><strong>Quantity:</strong> 
                                            <?= htmlspecialchars($rq['quantity_claim'] ?? 0) ?> /
                                            <?= htmlspecialchars($rq['total_quantity'] ?? 0) ?> Units
                                        </p>
                                        
                                        <!-- DELIVERY STATUS SECTION -->
                                        <p>
                                            <strong>Delivery Status:</strong>
                                            <span class="delivery-badge 
                                                <?= strtolower($rq['delivery_status'] ?? 'pending') == 'waiting for pickup' ? 'ds-ready' :
                                                (strtolower($rq['delivery_status'] ?? 'pending') == 'at sorting facility' ? 'ds-sorting' :
                                                (strtolower($rq['delivery_status'] ?? 'pending') == 'on the way' ? 'ds-transit' :
                                                (strtolower($rq['delivery_status'] ?? 'pending') == 'delivered' ? 'ds-delivered' :
                                                (strtolower($rq['delivery_status'] ?? 'pending') == 'cancelled' ? 'ds-cancelled' : 'ds-pending')))) ?>">
                                                <?= htmlspecialchars(ucwords($rq['delivery_status'] ?? 'Pending')) ?>
                                            </span>
                                        </p>

                                        <?php if (!empty($rq['delivery_start'])): ?>
                                            <p><strong>Estimated Delivery:</strong> 
                                                <?= date("M d, Y", strtotime($rq['delivery_start'])) ?>
                                                <?php if (!empty($rq['delivery_end']) && $rq['delivery_end'] != $rq['delivery_start']): ?>
                                                    - <?= date("M d, Y", strtotime($rq['delivery_end'])) ?>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>

                                        <p><strong>Request Date:</strong> <?= date("M d, Y H:i", strtotime($rq['requested_at'])) ?></p>
                                        <p><strong>Urgency Level:</strong> <?= htmlspecialchars($rq['urgency_level'] ?? 'Normal') ?></p>
                                    </div>

                                    <div class="card-actions">
                                        <?php if ($status === 'pending'): ?>
                                            <button class="edit-request-btn" data-id="<?= htmlspecialchars($rq['request_id']); ?>">Edit Request</button>
                                            <button class="cancel-request-btn" data-id="<?= htmlspecialchars($rq['request_id']); ?>">Cancel Request</button>
                                        <?php elseif ($status === 'approved'): ?>
                                            <!-- Requester actions for approved requests -->
                                            <?php if (strtolower($rq['delivery_status'] ?? 'pending') === 'on the way'): ?>
                                                <button class="confirm-delivery-btn" data-id="<?= htmlspecialchars($rq['request_id']); ?>">Confirm Receipt</button>
                                            <?php endif; ?>
                                            <button class="view-details-btn" data-id="<?= htmlspecialchars($rq['request_id']); ?>">View Details</button>
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
    <div class="modal-content" style="max-width:700px;">
        <span class="close">&times;</span>
        <h3>Request Details</h3>
        <div class="details-body">
            <div class="request-info-section">
                <h4>Request Information</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <strong>Donation By:</strong> <span id="modal-donor-name"></span>
                    </div>
                    <div class="info-item">
                        <strong>Requested By:</strong> <span id="modal-requester-name">You</span>
                    </div>
                    <div class="info-item">
                        <strong>Project Name:</strong> <span id="modal-project-name"></span>
                    </div>
                    <div class="info-item">
                        <strong>Type of Waste:</strong> <span id="modal-category"></span>
                    </div>
                    <div class="info-item">
                        <strong>Quantity:</strong> <span id="modal-quantity"></span>
                    </div>
                    <div class="info-item">
                        <strong>Urgency Level:</strong> <span id="modal-urgency"></span>
                    </div>
                    <div class="info-item">
                        <strong>Request Date:</strong> <span id="modal-date"></span>
                    </div>
                </div>
            </div>

            <div class="delivery-timeline-section">
                <h4>Delivery Timeline</h4>
                <div class="timeline">
                    <div class="timeline-item" id="timeline-pending">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <strong>Request Submitted</strong>
                            <span class="timeline-date" id="timeline-requested-date"></span>
                        </div>
                    </div>
                    
                    <div class="timeline-item" id="timeline-pickup">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <strong>Ready for Pickup</strong>
                            <span class="timeline-date" id="timeline-pickup-date">Pending</span>
                        </div>
                    </div>
                    
                    <div class="timeline-item" id="timeline-sorting">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <strong>At Sorting Facility</strong>
                            <span class="timeline-date" id="timeline-sorting-date">Pending</span>
                        </div>
                    </div>
                    
                    <div class="timeline-item" id="timeline-transit">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <strong>In Transit</strong>
                            <span class="timeline-date" id="timeline-transit-date">Pending</span>
                        </div>
                    </div>
                    
                    <div class="timeline-item" id="timeline-delivered">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <strong>Delivered</strong>
                            <span class="timeline-date" id="timeline-delivered-date">Pending</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="current-status-section">
                <h4>Current Status</h4>
                <div class="status-summary">
                    <p><strong>Delivery Status:</strong> <span id="modal-delivery-status"></span></p>
                    <p id="modal-next-step" style="font-style: italic; color: #666;"></p>
                </div>
            </div>

            <div id="modal-donation-image" style="margin-top:20px;"></div>
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

                                    <!-- Display Delivery Status if approved -->
                                    <?php if (strtolower($r['request_status']) === 'approved'): ?>
                                        <p><strong>Delivery Status:</strong> 
                                            <span class="delivery-status 
                                                <?= ($r['delivery_status'] ?? 'Pending') == 'Waiting for Pickup' ? 'ds-ready' :
                                                (($r['delivery_status'] ?? 'Pending') == 'At Sorting Facility' ? 'ds-sorting' :
                                                (($r['delivery_status'] ?? 'Pending') == 'On the Way' ? 'ds-transit' :
                                                (($r['delivery_status'] ?? 'Pending') == 'Delivered' ? 'ds-delivered' :
                                                (($r['delivery_status'] ?? 'Pending') == 'Cancelled' ? 'ds-cancelled' : 'ds-pending')))) ?>">
                                                <?= htmlspecialchars($r['delivery_status'] ?? 'Pending') ?>
                                            </span>
                                        </p>
                                        <?php if (!empty($r['delivery_start'])): ?>
                                            <p><strong>Estimated Delivery:</strong> 
                                                <?= date("M d, Y", strtotime($r['delivery_start'])) ?>
                                                <?php if (!empty($r['delivery_end']) && $r['delivery_end'] != $r['delivery_start']): ?>
                                                    - <?= date("M d, Y", strtotime($r['delivery_end'])) ?>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <div class="card-actions">
                                        <?php if (strtolower($r['request_status']) === 'pending'): ?>
                                            <button class="approve-request-btn" data-id="<?= $r['request_id'] ?>">Approve</button>
                                            <button class="decline-request-btn" data-id="<?= $r['request_id'] ?>">Decline</button>
                                            <?php elseif (strtolower($r['request_status']) === 'approved'): ?>
                                                <button class="manage-status-btn" data-id="<?= $r['request_id'] ?>">Manage Status</button>
                                            <?php elseif (strtolower($r['request_status']) === 'declined'): ?>
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

                    <!-- Enhanced Status Management Modal -->
                    <div id="statusManagementModal" class="modal" style="display:none;">
                        <div class="modal-content" style="max-width:600px;">
                            <span class="close">&times;</span>
                            <h3>Manage Delivery Status</h3>
                            <div class="status-management-body">
                                <div class="request-info-card">
                                    <h4 id="status-item-name"></h4>
                                    <p><strong>Category:</strong> <span id="status-category"></span></p>
                                    <p><strong>Requested by:</strong> <span id="status-requester"></span></p>
                                    <p><strong>Project:</strong> <span id="status-project"></span></p>
                                </div>
                                
                                <div class="status-checklist">
                                    <div class="status-step" data-status="Pending">
                                        <div class="step-header">
                                            <span class="step-number">1</span>
                                            <span class="step-title">Pending Item</span>
                                        </div>
                                        <div class="step-date-display" style="display:none;"></div>
                                        <p class="step-note">Initial status when request is approved</p>
                                    </div>
                                    
                                    <div class="status-step" data-status="Waiting for Pickup">
                                        <div class="step-header">
                                            <span class="step-number">2</span>
                                            <span class="step-title">Ready for Pickup</span>
                                            <div class="action-buttons"></div>
                                        </div>
                                        <div class="step-details" style="display:none;">
                                            <label>Estimated Pickup Date:</label>
                                            <input type="date" class="estimated-date pickup-date" data-status="Waiting for Pickup">
                                            
                                            <!-- NEW: Estimated Delivery Date Field -->
                                            <label style="margin-top: 10px;">Estimated Delivery End Date:</label>
                                            <input type="date" class="estimated-delivery-date" data-status="Waiting for Pickup">
                                            <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                                This will set the delivery date range: [Pickup Date] - [This Date]
                                            </small>
                                        </div>
                                        <div class="step-date-display" style="display:none;"></div>
                                    </div>
                                    
                                    <div class="status-step" data-status="At Sorting Facility">
                                        <div class="step-header">
                                            <span class="step-number">3</span>
                                            <span class="step-title">At Sorting Facility</span>
                                            <div class="action-buttons"></div>
                                        </div>
                                        <div class="step-details" style="display:none;">
                                            <label>Estimated Processing Date:</label>
                                            <input type="date" class="estimated-date sorting-date" data-status="At Sorting Facility">
                                        </div>
                                        <div class="step-date-display" style="display:none;"></div>
                                    </div>
                                    
                                    <div class="status-step" data-status="On the Way">
                                        <div class="step-header">
                                            <span class="step-number">4</span>
                                            <span class="step-title">In Transit</span>
                                            <div class="action-buttons"></div>
                                        </div>
                                        <div class="step-details" style="display:none;">
                                            <label>Estimated Delivery Date:</label>
                                            <input type="date" class="estimated-date transit-date" data-status="On the Way">
                                        </div>
                                        <div class="step-date-display" style="display:none;"></div>
                                    </div>
                                    
                                    <div class="status-step" data-status="Delivered">
                                        <div class="step-header">
                                            <span class="step-number">5</span>
                                            <span class="step-title">Delivered</span>
                                        </div>
                                        <div class="step-date-display" style="display:none;"></div>
                                        <p class="step-note">Status will update automatically when requester confirms receipt</p>
                                    </div>
                                    
                                    <div class="status-step" data-status="Cancelled">
                                        <div class="step-header">
                                            <span class="step-number">—</span>
                                            <span class="step-title">Cancelled</span>
                                        </div>
                                        <div class="step-date-display" style="display:none;"></div>
                                        <p class="step-note">Status will update automatically if requester cancels</p>
                                    </div>
                                </div>
                                
                                <div class="current-status-display">
                                    <strong>Current Status:</strong>
                                    <span id="current-delivery-status" class="status-badge"></span>
                                    <span id="estimated-delivery-text" class="estimated-text"></span>
                                </div>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" id="closeStatusModal">Close</button>
                            </div>
                        </div>
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

// ===================== ENHANCED VIEW REQUEST DETAILS MODAL ====================
$(document).on('click', '.view-details-btn', function() {
    const requestId = $(this).data('id');

    $.ajax({
        url: 'donate_process.php',
        method: 'GET',
        data: { 
            action: 'get_request_details', 
            request_id: requestId 
        },
        dataType: 'json',

        success: function(res) {
            if (res.status === 'success' && res.data) {
                const d = res.data;
                
                // Populate basic request info
                $('#modal-donor-name').text(d.donor_name || 'Unknown');
                $('#modal-project-name').text(d.project_name || '—');
                $('#modal-category').text((d.category || '') + (d.subcategory ? ' → ' + d.subcategory : ''));
                $('#modal-quantity').text(d.quantity_claim || '—');
                $('#modal-urgency').text(d.urgency_level || '—');
                $('#modal-date').text(d.requested_at ? new Date(d.requested_at).toLocaleString() : '—');

                // ==================== ENHANCED TIMELINE DISPLAY ====================
                const currentStatus = d.delivery_status || 'Pending';
                
                // Format dates for display
                const formatDateTime = (dateString) => {
                    if (!dateString) return null;
                    return new Date(dateString).toLocaleString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                };

                const formatDate = (dateString) => {
                    if (!dateString) return null;
                    return new Date(dateString).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                };

                // Reset timeline
                $('.timeline-item').removeClass('completed current upcoming');
                $('.timeline-date').text('Pending');

                // Request Submitted (always completed)
                $('#timeline-pending').addClass('completed');
                $('#timeline-requested-date').text(formatDateTime(d.requested_at) || '—');

                // Ready for Pickup
                if (d.pickup_date) {
                    $('#timeline-pickup').addClass('completed');
                    $('#timeline-pickup-date').text(formatDateTime(d.pickup_date));
                } else if (['Waiting for Pickup', 'At Sorting Facility', 'On the Way', 'Delivered'].includes(currentStatus)) {
                    $('#timeline-pickup').addClass('current');
                    $('#timeline-pickup-date').text('Scheduled');
                }

                // At Sorting Facility
                if (d.sorting_facility_date) {
                    $('#timeline-sorting').addClass('completed');
                    $('#timeline-sorting-date').text(formatDateTime(d.sorting_facility_date));
                } else if (['At Sorting Facility', 'On the Way', 'Delivered'].includes(currentStatus)) {
                    $('#timeline-sorting').addClass('current');
                    $('#timeline-sorting-date').text('In Progress');
                } else if (d.pickup_date) {
                    $('#timeline-sorting').addClass('upcoming');
                }

                // In Transit
                if (d.in_transit_date) {
                    $('#timeline-transit').addClass('completed');
                    $('#timeline-transit-date').text(formatDateTime(d.in_transit_date));
                } else if (['On the Way', 'Delivered'].includes(currentStatus)) {
                    $('#timeline-transit').addClass('current');
                    $('#timeline-transit-date').text('Estimated: ' + (d.delivery_start ? formatDate(d.delivery_start) : 'Soon'));
                } else if (d.sorting_facility_date) {
                    $('#timeline-transit').addClass('upcoming');
                }

                // Delivered
                if (d.delivered_date) {
                    $('#timeline-delivered').addClass('completed');
                    $('#timeline-delivered-date').text(formatDateTime(d.delivered_date));
                } else if (currentStatus === 'Delivered') {
                    $('#timeline-delivered').addClass('current');
                    $('#timeline-delivered-date').text('Completed');
                } else if (d.in_transit_date) {
                    $('#timeline-delivered').addClass('upcoming');
                }

                // ==================== CURRENT STATUS DISPLAY ====================
                const deliveryStatus = (d.delivery_status || '').toLowerCase();
                const formattedStatus = d.delivery_status ? d.delivery_status.replace(/\b\w/g, c => c.toUpperCase()) : 'Pending';
                
                $('#modal-delivery-status')
                    .text(formattedStatus)
                    .css({
                        padding: '6px 12px',
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
                    });

                // ==================== ESTIMATED DELIVERY DATE DISPLAY ====================
                // Add estimated delivery date to the request information section
                let estimatedDeliveryHtml = '';
                if (d.delivery_start && d.delivery_end) {
                    const startDate = formatDate(d.delivery_start);
                    const endDate = formatDate(d.delivery_end);
                    estimatedDeliveryHtml = `
                        <div class="info-item">
                            <strong>Estimated Delivery:</strong> 
                            <span style="color: #2e8b57; font-weight: 600;">${startDate} - ${endDate}</span>
                        </div>
                    `;
                } else if (d.delivery_start) {
                    estimatedDeliveryHtml = `
                        <div class="info-item">
                            <strong>Estimated Delivery:</strong> 
                            <span style="color: #2e8b57; font-weight: 600;">${formatDate(d.delivery_start)}</span>
                        </div>
                    `;
                } else if (d.delivery_status === 'Waiting for Pickup' || d.delivery_status === 'At Sorting Facility') {
                    estimatedDeliveryHtml = `
                        <div class="info-item">
                            <strong>Estimated Delivery:</strong> 
                            <span style="color: #ff9800; font-style: italic;">To be determined</span>
                        </div>
                    `;
                }

                // Insert estimated delivery after the urgency level in the info grid
                if (estimatedDeliveryHtml) {
                    // Find the urgency level item and insert after it
                    $('.info-item:contains("Urgency Level")').after(estimatedDeliveryHtml);
                }

                // Next step information
                let nextStep = '';
                switch(currentStatus) {
                    case 'Pending':
                        nextStep = 'Waiting for donor to approve and schedule pickup';
                        break;
                    case 'Waiting for Pickup':
                        if (d.delivery_start) {
                            nextStep = `Item is ready for pickup. Estimated delivery: ${formatDate(d.delivery_start)}`;
                        } else {
                            nextStep = 'Item is ready for pickup. Waiting for donor to provide delivery estimate.';
                        }
                        break;
                    case 'At Sorting Facility':
                        if (d.delivery_start) {
                            nextStep = `Item is being processed. Estimated delivery: ${formatDate(d.delivery_start)}`;
                        } else {
                            nextStep = 'Item is being processed at the sorting facility.';
                        }
                        break;
                    case 'On the Way':
                        if (d.delivery_start) {
                            nextStep = `Item is on its way! Estimated delivery: ${formatDate(d.delivery_start)}`;
                        } else {
                            nextStep = 'Item is on its way to you.';
                        }
                        break;
                    case 'Delivered':
                        nextStep = 'Delivery completed successfully.';
                        break;
                    case 'Cancelled':
                        nextStep = 'This request has been cancelled.';
                        break;
                }
                $('#modal-next-step').text(nextStep);

                // ==================== IMAGE DISPLAY ====================
                let imageHtml = '';
                if (d.image_path) {
                    try {
                        // Try to parse as JSON (if it's an array of images)
                        const images = JSON.parse(d.image_path);
                        if (Array.isArray(images) && images.length > 0) {
                            images.forEach(img => {
                                imageHtml += `<img src="${img}" alt="Donation image" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #ddd; margin:5px;">`;
                            });
                        }
                    } catch (e) {
                        // If not JSON, treat as single image path
                        imageHtml = `<img src="${d.image_path}" alt="Donation image" style="max-width:100%;max-height:300px;border-radius:8px;border:1px solid #ddd;">`;
                    }
                } else if (d.image) {
                    imageHtml = `<img src="uploads/${d.image}" alt="Donation image" style="max-width:100%;max-height:300px;border-radius:8px;border:1px solid #ddd;">`;
                } else if (d.donation_image) {
                    imageHtml = `<img src="uploads/${d.donation_image}" alt="Donation image" style="max-width:100%;max-height:300px;border-radius:8px;border:1px solid #ddd;">`;
                }

                $('#modal-donation-image').html(
                    imageHtml 
                        ? `<div style="text-align: center; margin-top: 15px;">
                            <h4>Donation Images</h4>
                            ${imageHtml}
                        </div>`
                        : '<div style="text-align: center; color: #666; font-style: italic; margin-top: 15px;">No image available</div>'
                );

                // ==================== CANCEL BUTTON LOGIC ====================
                const deliveryPending = !d.delivery_start && !d.delivery_end && 
                                      currentStatus !== 'Delivered' && 
                                      currentStatus !== 'Cancelled' &&
                                      currentStatus !== 'On the Way';

                if (deliveryPending) {
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
                    $('#modal-cancel-request')
                        .prop('disabled', true)
                        .css({
                            backgroundColor: '#b0b0b0',
                            color: '#fff',
                            cursor: 'not-allowed',
                            opacity: 0.8
                        })
                        .off('mouseenter mouseleave');
                    
                    let warningText = 'Cannot cancel request. ';
                    if (currentStatus === 'Delivered') {
                        warningText += 'Delivery has already been completed.';
                    } else if (currentStatus === 'Cancelled') {
                        warningText += 'Request has already been cancelled.';
                    } else if (currentStatus === 'On the Way') {
                        warningText += 'Item is already in transit.';
                    } else {
                        warningText += 'Delivery is in progress.';
                    }
                    $('#cancel-warning').text(warningText).show();
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

// ==================== ENHANCED STATUS MANAGEMENT MODAL WITH STATE PERSISTENCE ====================
let statusModalState = {
    currentRequestId: null,
    currentStatus: null,
    stepStates: {},
    editMode: false
};

$(document).on('click', '.manage-status-btn', function() {
    const requestId = $(this).data('id');
    statusModalState.currentRequestId = requestId;
    
    // Fetch request details
    $.get('donate_process.php', { 
        action: 'get_request_details', 
        request_id: requestId 
    }, function(response) {
        try {
            const res = typeof response === 'object' ? response : JSON.parse(response);
            
            if (res.status === 'success' && res.data) {
                const data = res.data;
                
                // Populate modal with request info
                $('#status-item-name').text(data.item_name || data.subcategory || 'Donation Item');
                $('#status-category').text(data.category || '—');
                $('#status-requester').text(data.first_name + ' ' + data.last_name);
                $('#status-project').text(data.project_name || '—');
                
                // Set current status
                const currentStatus = data.delivery_status || 'Pending';
                statusModalState.currentStatus = currentStatus;
                
                $('#current-delivery-status')
                    .text(currentStatus)
                    .removeClass()
                    .addClass('status-badge ' + 
                        (currentStatus === 'Waiting for Pickup' ? 'ds-ready' :
                         currentStatus === 'At Sorting Facility' ? 'ds-sorting' :
                         currentStatus === 'On the Way' ? 'ds-transit' :
                         currentStatus === 'Delivered' ? 'ds-delivered' :
                         currentStatus === 'Cancelled' ? 'ds-cancelled' : 'ds-pending'));
                
                // Store request data
                $('#statusManagementModal').data('request-data', data);
                $('#statusManagementModal').data('request-id', requestId);
                
                // Reset modal state and initialize with fresh database data
                resetStatusModalState();
                statusModalState.currentRequestId = requestId;
                statusModalState.currentStatus = currentStatus;

                // Initialize steps with fresh database data
                initializeStatusStepsWithPersistence(currentStatus, data);
                
                // Show modal
                $('#statusManagementModal').fadeIn(200);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load request details' });
            }
        } catch (e) {
            console.error('Error parsing response:', e);
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load request details' });
        }
    });
});

// Fixed function to initialize status steps with proper state loading from database
function initializeStatusStepsWithPersistence(currentStatus, requestData) {
    const statusOrder = ['Pending', 'Waiting for Pickup', 'At Sorting Facility', 'On the Way', 'Delivered', 'Cancelled'];
    const currentIndex = statusOrder.indexOf(currentStatus);
    
    // Reset all steps but preserve edit mode if active
    $('.status-step').removeClass('completed current upcoming editable');
    if (!statusModalState.editMode) {
        $('.status-step').removeClass('edit-mode');
    }
    $('.step-details').hide();
    $('.step-date-display').hide().empty();
    $('.action-buttons').empty();
    
    $('.status-step').each(function() {
        const stepStatus = $(this).data('status');
        const stepIndex = statusOrder.indexOf(stepStatus);
        const stepHeader = $(this).find('.step-header');
        const actionButtons = $(this).find('.action-buttons');
        
        // Clear existing badges
        stepHeader.find('.step-badge').remove();
        
        // Get step-specific date from ACTUAL DATABASE DATA
        let stepDate = '';
        let dateField = '';
        let isStepCompleted = false;
        
        switch(stepStatus) {
            case 'Waiting for Pickup':
                stepDate = requestData.pickup_date; // From database
                dateField = 'pickup-date';
                isStepCompleted = stepDate !== null && stepDate !== undefined && stepDate !== '';
                break;
            case 'At Sorting Facility':
                stepDate = requestData.sorting_facility_date; // From database
                dateField = 'sorting-date';
                isStepCompleted = stepDate !== null && stepDate !== undefined && stepDate !== '';
                break;
            case 'On the Way':
                stepDate = requestData.in_transit_date; // From database
                dateField = 'transit-date';
                isStepCompleted = stepDate !== null && stepDate !== undefined && stepDate !== '';
                break;
            case 'Delivered':
                stepDate = requestData.delivered_date; // From database
                isStepCompleted = stepDate !== null && stepDate !== undefined && stepDate !== '';
                break;
        }
        
        // Check if this step is in edit mode from our state
        const isInEditMode = statusModalState.stepStates[stepStatus] === 'edit';
        
        // FIXED: Determine step state based on ACTUAL DATABASE STATUS, not just currentStatus
        if (stepIndex < currentIndex || isStepCompleted) {
            // COMPLETED STEP - Show Edit button (or in edit mode)
            $(this).addClass('completed');
            
            if (isInEditMode) {
                $(this).addClass('edit-mode');
                stepHeader.append('<span class="step-badge status-current">Editing</span>');
                
                // Show date inputs for editing
                $(this).find('.step-details').show();
                
                // Add Save and Cancel buttons
                const saveBtn = $(`<button class="save-edit-btn" data-status="${stepStatus}">Save Changes</button>`);
                const cancelBtn = $(`<button class="cancel-edit-btn" data-status="${stepStatus}">Cancel</button>`);
                actionButtons.append(saveBtn, cancelBtn);
            } else {
                stepHeader.append('<span class="step-badge status-completed">Completed</span>');
                
                // Show date display from ACTUAL DATABASE
                if (stepDate) {
                    const displayText = getDateDisplayText(stepStatus, stepDate);
                    $(this).find('.step-date-display').html(displayText).show();
                }
                
                // Add Edit button for editable completed steps
                if (stepStatus === 'Waiting for Pickup' || stepStatus === 'At Sorting Facility' || stepStatus === 'On the Way') {
                    const editBtn = $(`<button class="edit-step-btn" data-status="${stepStatus}">Edit</button>`);
                    actionButtons.append(editBtn);
                }
            }
            
        } else if (stepIndex === currentIndex) {
            // CURRENT STEP - Show Mark button
            $(this).addClass('current');
            stepHeader.append('<span class="step-badge status-current">Current</span>');
            
            // Show date input for current editable steps
            if (stepStatus === 'Waiting for Pickup' || stepStatus === 'At Sorting Facility' || stepStatus === 'On the Way') {
                $(this).find('.step-details').show();
                const markBtn = $(`<button class="update-status-btn" data-status="${stepStatus}">Mark as ${getButtonText(stepStatus)}</button>`);
                actionButtons.append(markBtn);
            }
            
        } else if (stepIndex > currentIndex) {
            // FUTURE STEP - Show upcoming state
            $(this).addClass('upcoming');
            
            // Show date input for future editable steps
            if (stepStatus === 'Waiting for Pickup' || stepStatus === 'At Sorting Facility' || stepStatus === 'On the Way') {
                $(this).find('.step-details').show();
                const markBtn = $(`<button class="update-status-btn" data-status="${stepStatus}">Mark as ${getButtonText(stepStatus)}</button>`);
                actionButtons.append(markBtn);
            }
        }
        
        // Auto steps (no manual interaction)
        if (stepStatus === 'Pending' || stepStatus === 'Delivered' || stepStatus === 'Cancelled') {
            stepHeader.append('<span class="step-badge status-auto">Auto</span>');
        }
        
        // Pre-fill date inputs from ACTUAL DATABASE DATA
        if (stepDate && dateField) {
            $(this).find(`.${dateField}`).val(formatDateForInput(stepDate));
        }

        // NEW: Pre-fill estimated delivery date for Waiting for Pickup
        if (stepStatus === 'Waiting for Pickup' && requestData.delivery_end) {
            $(this).find('.estimated-delivery-date').val(formatDateForInput(requestData.delivery_end));
        }
    });
}

// Helper function for button text
function getButtonText(status) {
    switch(status) {
        case 'Waiting for Pickup': return 'Ready';
        case 'At Sorting Facility': return 'At Facility';
        case 'On the Way': return 'In Transit';
        default: return status;
    }
}

// Helper function to format dates for display
function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
}

// Helper function to format dates for input fields
function formatDateForInput(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toISOString().split('T')[0];
}

// Helper function to generate date display text
function getDateDisplayText(stepStatus, dateString) {
    const formattedDate = formatDate(dateString);
    switch(stepStatus) {
        case 'Waiting for Pickup':
            return `<strong>Estimated Pickup:</strong> ${formattedDate}`;
        case 'At Sorting Facility':
            return `<strong>Processing Date:</strong> ${formattedDate}`;
        case 'On the Way':
            return `<strong>Estimated Delivery:</strong> ${formattedDate}`;
        case 'Delivered':
            return `<strong>Delivered On:</strong> ${formattedDate}`;
        default:
            return `<strong>Date:</strong> ${formattedDate}`;
    }
}

// ==================== MARK AS (STATUS PROGRESSION) ====================
$(document).on('click', '.update-status-btn', function() {
    const status = $(this).data('status');
    const requestId = $('#statusManagementModal').data('request-id');
    const stepElement = $(this).closest('.status-step');
    
    // Get the date input
    let estimatedDate = '';
    switch(status) {
        case 'Waiting for Pickup':
            estimatedDate = stepElement.find('.pickup-date').val();
            break;
        case 'At Sorting Facility':
            estimatedDate = stepElement.find('.sorting-date').val();
            break;
        case 'On the Way':
            estimatedDate = stepElement.find('.transit-date').val();
            break;
    }
    
    // Validate date for required steps
    if ((status === 'At Sorting Facility' || status === 'On the Way') && !estimatedDate) {
        Swal.fire({ 
            icon: 'warning', 
            title: 'Date Required', 
            text: 'Please provide an estimated date before updating the status.' 
        });
        return;
    }
    
    // For Waiting for Pickup, validate both dates
    if (status === 'Waiting for Pickup') {
        const estimatedDeliveryDate = stepElement.find('.estimated-delivery-date').val();
        
        if (!estimatedDate) {
            Swal.fire({ 
                icon: 'warning', 
                title: 'Pickup Date Required', 
                text: 'Please provide an estimated pickup date.' 
            });
            return;
        }
        if (!estimatedDeliveryDate) {
            Swal.fire({ 
                icon: 'warning', 
                title: 'Delivery Date Required', 
                text: 'Please provide an estimated delivery end date.' 
            });
            return;
        }
        
        // Validate that delivery date is after pickup date
        const pickupDate = new Date(estimatedDate);
        const deliveryDate = new Date(estimatedDeliveryDate);
        if (deliveryDate <= pickupDate) {
            Swal.fire({ 
                icon: 'warning', 
                title: 'Invalid Date Range', 
                text: 'Estimated delivery date must be after the pickup date.' 
            });
            return;
        }
    }
    
    Swal.fire({
        title: 'Update Status?',
        text: `Mark this item as ${status}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Update',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            updateDeliveryStatus(requestId, status, estimatedDate, stepElement);
        }
    });
});


// Enhanced status update function with better delivery date handling
function updateDeliveryStatus(requestId, status, estimatedDate, stepElement) {
    const statusConversion = {
        'Pending': 'pending',
        'Waiting for Pickup': 'waiting_for_pickup',
        'At Sorting Facility': 'at_sorting_facility',
        'On the Way': 'on_the_way',
        'Delivered': 'delivered',
        'Cancelled': 'cancelled'
    };
    
    const backendStatus = statusConversion[status] || status.toLowerCase().replace(/ /g, '_');
    
    // Get estimated delivery date for "Waiting for Pickup"
    let estimatedDeliveryDate = '';
    if (status === 'Waiting for Pickup') {
        estimatedDeliveryDate = stepElement.find('.estimated-delivery-date').val();
        
        console.log('Dates collected:', {
            pickupDate: estimatedDate,
            deliveryEndDate: estimatedDeliveryDate
        });
    }
    
    // Prepare the data to send - FIXED: Use proper form data format
    const postData = new FormData();
    postData.append('action', 'update_delivery_status');
    postData.append('request_id', requestId);
    postData.append('delivery_status', backendStatus);
    
    if (estimatedDate) {
        postData.append('estimated_delivery', estimatedDate);
    }
    
    // Add estimated delivery date only for Waiting for Pickup - FIXED: Always include when available
    if (status === 'Waiting for Pickup' && estimatedDeliveryDate) {
        postData.append('estimated_delivery_date', estimatedDeliveryDate);
    }
    
    console.log('Sending data to server:');
    for (let [key, value] of postData.entries()) {
        console.log(key + ': ' + value);
    }
    
    $.ajax({
        url: 'donate_process.php',
        method: 'POST',
        data: postData,
        processData: false,
        contentType: false,
        success: function(response) {
            console.log('Server response:', response);
            try {
                const res = typeof response === 'object' ? response : JSON.parse(response);
                
                if (res.status === 'success') {
                    // Update modal state
                    statusModalState.currentStatus = status;
                    statusModalState.editMode = false;
                    delete statusModalState.stepStates[status]; // Remove edit state for this step
                    
                    // Update UI immediately
                    updateStepToCompleted(stepElement, status, estimatedDate);
                    
                    // Update the specific request card in real-time
                    updateRequestCardInRealTime(requestId, status, estimatedDate, estimatedDeliveryDate);
                    
                    Swal.fire({ 
                        icon: 'success', 
                        title: 'Updated!', 
                        text: res.message || 'Status updated successfully',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to update status' });
                }
            } catch (e) {
                console.error('Error parsing response:', e, response);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to update status' });
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error:', status, error);
            Swal.fire({ icon: 'error', title: 'Server Error', text: 'Failed to update status' });
        }
    });
}


// Update step to completed state with Edit button
function updateStepToCompleted(stepElement, status, estimatedDate) {
    const stepHeader = stepElement.find('.step-header');
    const actionButtons = stepElement.find('.action-buttons');
    
    // Update step classes
    stepElement.removeClass('current upcoming edit-mode').addClass('completed');
    
    // Update badge
    stepHeader.find('.step-badge').remove();
    stepHeader.append('<span class="step-badge status-completed">Completed</span>');
    
    // Show date display
    if (estimatedDate) {
        const displayText = getDateDisplayText(status, estimatedDate);
        stepElement.find('.step-date-display').html(displayText).show();
    }
    
    // Replace Mark button with Edit button
    actionButtons.empty();
    if (status === 'Waiting for Pickup' || status === 'At Sorting Facility' || status === 'On the Way') {
        const editBtn = $(`<button class="edit-step-btn" data-status="${status}">Edit</button>`);
        actionButtons.append(editBtn);
    }
    
    // Hide date inputs
    stepElement.find('.step-details').hide();
}

// ==================== EDIT MODE FUNCTIONALITY ====================
$(document).on('click', '.edit-step-btn', function() {
    const status = $(this).data('status');
    const stepElement = $(this).closest('.status-step');
    const actionButtons = stepElement.find('.action-buttons');
    
    // Enter edit mode and update state
    stepElement.addClass('edit-mode');
    statusModalState.editMode = true;
    statusModalState.stepStates[status] = 'edit';
    
    // Show date inputs
    stepElement.find('.step-details').show();
    
    // Replace Edit button with Save and Cancel buttons
    actionButtons.empty();
    
    const saveBtn = $(`<button class="save-edit-btn" data-status="${status}">Save Changes</button>`);
    const cancelBtn = $(`<button class="cancel-edit-btn" data-status="${status}">Cancel</button>`);
    
    actionButtons.append(saveBtn, cancelBtn);
});

// Save edited date
$(document).on('click', '.save-edit-btn', function() {
    const status = $(this).data('status');
    const requestId = $('#statusManagementModal').data('request-id');
    const stepElement = $(this).closest('.status-step');
    const actionButtons = stepElement.find('.action-buttons');
    
    // Get the updated date
    let estimatedDate = '';
    switch(status) {
        case 'Waiting for Pickup':
            estimatedDate = stepElement.find('.pickup-date').val();
            break;
        case 'At Sorting Facility':
            estimatedDate = stepElement.find('.sorting-date').val();
            break;
        case 'On the Way':
            estimatedDate = stepElement.find('.transit-date').val();
            break;
    }
    
    if (!estimatedDate) {
        Swal.fire({ icon: 'warning', title: 'Date Required', text: 'Please provide a date.' });
        return;
    }
    
    // Update the date via AJAX
    updateDeliveryDate(requestId, status, estimatedDate, stepElement, actionButtons);
});

// Cancel edit mode
$(document).on('click', '.cancel-edit-btn', function() {
    const status = $(this).data('status');
    const stepElement = $(this).closest('.status-step');
    const actionButtons = stepElement.find('.action-buttons');
    
    // Exit edit mode and update state
    stepElement.removeClass('edit-mode');
    statusModalState.editMode = false;
    delete statusModalState.stepStates[status];
    
    stepElement.find('.step-details').hide();
    
    // Restore Edit button
    actionButtons.empty();
    const editBtn = $(`<button class="edit-step-btn" data-status="${status}">Edit</button>`);
    actionButtons.append(editBtn);
});

// Enhanced date update function
function updateDeliveryDate(requestId, status, estimatedDate, stepElement, actionButtons) {
    const statusConversion = {
        'Waiting for Pickup': 'waiting_for_pickup',
        'At Sorting Facility': 'at_sorting_facility',
        'On the Way': 'on_the_way'
    };
    
    const backendStatus = statusConversion[status];
    
    // Get estimated delivery date for "Waiting for Pickup" when editing
    let estimatedDeliveryDate = '';
    if (status === 'Waiting for Pickup') {
        estimatedDeliveryDate = stepElement.find('.estimated-delivery-date').val();
    }
    
    const postData = new FormData();
    postData.append('action', 'update_delivery_status');
    postData.append('request_id', requestId);
    postData.append('delivery_status', backendStatus);
    
    if (estimatedDate) {
        postData.append('estimated_delivery', estimatedDate);
    }
    
    // Include estimated delivery date for Waiting for Pickup
    if (status === 'Waiting for Pickup' && estimatedDeliveryDate) {
        postData.append('estimated_delivery_date', estimatedDeliveryDate);
    }
    
    console.log('Sending edit data to server:');
    for (let [key, value] of postData.entries()) {
        console.log(key + ': ' + value);
    }
    
    $.ajax({
        url: 'donate_process.php',
        method: 'POST',
        data: postData,
        processData: false,
        contentType: false,
        success: function(response) {
            try {
                const res = typeof response === 'object' ? response : JSON.parse(response);
                
                if (res.status === 'success') {
                    // Update date display
                    const displayText = getDateDisplayText(status, estimatedDate);
                    stepElement.find('.step-date-display').html(displayText).show();
                    
                    // Exit edit mode and update state
                    stepElement.removeClass('edit-mode');
                    statusModalState.editMode = false;
                    delete statusModalState.stepStates[status];
                    
                    stepElement.find('.step-details').hide();
                    
                    // Restore Edit button
                    actionButtons.empty();
                    const editBtn = $(`<button class="edit-step-btn" data-status="${status}">Edit</button>`);
                    actionButtons.append(editBtn);
                    
                    Swal.fire({ 
                        icon: 'success', 
                        title: 'Date Updated!', 
                        text: 'Delivery date has been updated successfully.',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    // Update request card in real-time
                    updateRequestCardInRealTime(requestId, status, estimatedDate, estimatedDeliveryDate);
                    
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to update date' });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to update date' });
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error:', status, error);
            Swal.fire({ icon: 'error', title: 'Server Error', text: 'Failed to update date' });
        }
    });
}

// Enhanced function to update specific request card in real-time
function updateRequestCardInRealTime(requestId, newStatus, estimatedDate, estimatedDeliveryDate) {
    const requestCard = $(`.donation-card:has(.manage-status-btn[data-id="${requestId}"])`);
    
    if (requestCard.length) {
        // Update delivery status display
        const deliveryStatusElement = requestCard.find('.delivery-status');
        const deliveryStatusText = requestCard.find('p:contains("Delivery Status:")');
        const estimatedDateElement = requestCard.find('p:contains("Estimated Delivery:")');
        
        // Update status badge
        deliveryStatusElement
            .text(newStatus)
            .removeClass()
            .addClass('delivery-status ' + 
                (newStatus === 'Waiting for Pickup' ? 'ds-ready' :
                 newStatus === 'At Sorting Facility' ? 'ds-sorting' :
                 newStatus === 'On the Way' ? 'ds-transit' :
                 newStatus === 'Delivered' ? 'ds-delivered' :
                 newStatus === 'Cancelled' ? 'ds-cancelled' : 'ds-pending'));
        
        // IMPORTANT: Only update Estimated Delivery display for Waiting for Pickup status
        // For other statuses, keep the original delivery range from Waiting for Pickup
        if (newStatus === 'Waiting for Pickup' && estimatedDate && estimatedDeliveryDate) {
            const formattedStartDate = formatDate(estimatedDate);
            const formattedEndDate = formatDate(estimatedDeliveryDate);
            const dateRangeText = `${formattedStartDate} - ${formattedEndDate}`;
            
            if (estimatedDateElement.length) {
                estimatedDateElement.html(`<strong>Estimated Delivery:</strong> ${dateRangeText}`);
            } else {
                // Create new estimated date element if it doesn't exist
                deliveryStatusText.after(`<p><strong>Estimated Delivery:</strong> ${dateRangeText}</p>`);
            }
        } else if (newStatus === 'Waiting for Pickup' && estimatedDate) {
            const formattedDate = formatDate(estimatedDate);
            if (estimatedDateElement.length) {
                estimatedDateElement.html(`<strong>Estimated Delivery:</strong> ${formattedDate}`);
            } else {
                deliveryStatusText.after(`<p><strong>Estimated Delivery:</strong> ${formattedDate}</p>`);
            }
        }

        
        // Add visual feedback
        requestCard.css('transition', 'all 0.3s ease');
        requestCard.css('background-color', '#f0fff0');
        setTimeout(() => {
            requestCard.css('background-color', '');
        }, 1000);
    } else {
        // Fallback: refresh the entire tab if specific card not found
        refreshRequestsForMeTab();
    }
}

// Function to refresh the "Requests for My Donations" tab
function refreshRequestsForMeTab() {
    const tabContent = $("#requests-for-me");
    
    // Show loading state
    const originalContent = tabContent.html();
    tabContent.html(`
        <div class="loading-state" style="text-align: center; padding: 40px;">
            <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #2e8b57;"></i>
            <p>Updating requests...</p>
        </div>
    `);
    
    // Reload the tab content
    setTimeout(() => {
        location.reload();
    }, 1000);
}

// Close status modal with state reset for new requests
$(document).on('click', '#closeStatusModal, #statusManagementModal .close', function() {
    // Only reset state if we're closing without a pending action
    if (!statusModalState.editMode) {
        resetStatusModalState();
    }
    $('#statusManagementModal').fadeOut(200);
});

$(window).on('click', function(e) {
    if ($(e.target).is('#statusManagementModal')) {
        // Only reset state if we're closing without a pending action
        if (!statusModalState.editMode) {
            resetStatusModalState();
        }
        $('#statusManagementModal').fadeOut(200);
    }
});

// Function to reset modal state when completely closed
function resetStatusModalState() {
    statusModalState = {
        currentRequestId: null,
        currentStatus: null,
        stepStates: {},
        editMode: false
    };
    
    // Also reset any UI elements that might retain state
    $('.status-step').removeClass('edit-mode');
    $('.step-details').hide();
}


// ==================== CONFIRM DELIVERY (REQUESTER ACTION) ====================
$(document).on('click', '.confirm-delivery-btn', function() {
    const requestId = $(this).data('id');
    
    Swal.fire({
        title: 'Confirm Delivery?',
        text: 'Please confirm that you have received the donation items.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, I Received It',
        cancelButtonText: 'Not Yet',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('donate_process.php', {
                action: 'confirm_delivery',
                request_id: requestId
            }, function(response) {
                try {
                    const res = typeof response === 'object' ? response : JSON.parse(response);
                    
                    if (res.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Delivery Confirmed!',
                            text: res.message || 'Thank you for confirming receipt!',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        
                        // Refresh the tab to show updated status
                        $("#my-requests").load(location.href + " #my-requests > *");
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: res.message || 'Failed to confirm delivery'
                        });
                    }
                } catch (e) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to confirm delivery'
                    });
                }
            });
        }
    });
});

// ==================== AUTO UPDATE CANCELLED STATUS ====================
// This would be called when requester cancels a request
function updateToCancelledStatus(requestId) {
    $.post('donate_process.php', {
        action: 'auto_update_cancelled',
        request_id: requestId
    }, function(response) {
        // Optional: handle response if needed
        console.log('Cancelled status updated:', response);
    });
}

// Update existing cancel request to also update delivery status
$(document).on("click", ".cancel-request-btn", function() {
    const btn = $(this);
    const requestId = btn.data("id");

    if (!requestId) return Swal.fire({ icon: "error", title: "Error", text: "Invalid request ID" });
    
    Swal.fire({
        title: 'Cancel Request?',
        text: 'This will cancel your donation request and update the status.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Cancel Request',
        cancelButtonText: 'Keep Request',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // First update to cancelled status
            updateToCancelledStatus(requestId);
            
            // Then proceed with existing cancel logic
            $.post("donate_process.php", { action: "cancel_request", request_id: requestId }, function(response) {
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

                        const removedCard = btn.closest(".donation-card");
                        removedCard.fadeOut(400, function() {
                            $(this).remove();

                            const myRequestsContainer = $("#my-requests");
                            if (myRequestsContainer.find(".donation-card").length === 0) {
                                let empty = myRequestsContainer.find(".empty-state").first();
                                if (empty.length === 0) {
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
                    } else {
                        Swal.fire({ icon: "error", title: "Failed", text: data.message || "Could not cancel request." });
                    }
                } catch (e) {
                    Swal.fire({ icon: "error", title: "Error", text: "Invalid server response." });
                    console.error("Invalid JSON:", response, e);
                }
            });
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