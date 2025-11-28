<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

$action = $_REQUEST['action'] ?? '';

if ($action === 'view_donation' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $donation_id = intval($_GET['donation_id'] ?? 0);
    if ($donation_id <= 0) {
        echo "<p>Invalid donation ID.</p>";
        exit;
    }

    // Fetch donation with donor info
    $stmt = $conn->prepare("
        SELECT d.*, 
               donor.first_name as donor_first_name, 
               donor.last_name as donor_last_name,
               receiver.first_name as receiver_first_name,
               receiver.last_name as receiver_last_name
        FROM donations d
        LEFT JOIN users donor ON d.donor_id = donor.user_id
        LEFT JOIN users receiver ON d.receiver_id = receiver.user_id
        WHERE d.donation_id = ?
    ");
    $stmt->bind_param("i", $donation_id);
    $stmt->execute();
    $donation = $stmt->get_result()->fetch_assoc();

    if (!$donation) {
        echo "<p>Donation not found.</p>";
        exit;
    }

    // Fetch comments
    $stmt = $conn->prepare("
        SELECT c.comment_id, c.comment_text, c.created_at, u.first_name, u.last_name
        FROM comments c
        JOIN users u ON c.user_id = u.user_id
        WHERE c.donation_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->bind_param("i", $donation_id);
    $stmt->execute();
    $comments = $stmt->get_result();

    // Fetch requests for this donation
    $stmt = $conn->prepare("
        SELECT dr.*, u.first_name, u.last_name, p.project_name
        FROM donation_requests dr
        JOIN users u ON dr.user_id = u.user_id
        LEFT JOIN projects p ON dr.project_id = p.project_id
        WHERE dr.donation_id = ?
        ORDER BY dr.requested_at DESC
    ");
    $stmt->bind_param("i", $donation_id);
    $stmt->execute();
    $requests = $stmt->get_result();
    
    $request_count = $requests->num_rows;

    ?>
    <div class="donation-post">
        <h3><?= htmlspecialchars($donation['item_name']); ?></h3>
        
        <div class="donation-description">
            <p><?= nl2br(htmlspecialchars($donation['description'] ?? 'No description provided')); ?></p>
        </div>

        <?php if (!empty($donation['image_path'])):
            $images = json_decode($donation['image_path'], true);
            if (is_array($images)): ?>
                <div class="donation-images">
                    <?php foreach ($images as $img): ?>
                        <img src="<?= htmlspecialchars($img); ?>" class="donation-image" alt="">
                    <?php endforeach; ?>
                </div>
            <?php endif;
        endif; ?>

        <!-- Donation Information Grid -->
        <div class="donation-info-grid">
            <div class="info-item">
                <strong>Donated:</strong>
                <span><?= $donation['donated_at'] ? date("M d, Y", strtotime($donation['donated_at'])) : '—' ?></span>
            </div>
            
            <div class="info-item">
                <strong>Types of waste:</strong>
                <span><?= htmlspecialchars($donation['category'] ?? '—') ?></span>
            </div>
            
            <div class="info-item">
                <strong>Quantity:</strong>
                <span><?= $donation['quantity'] ?>/<?= $donation['total_quantity'] ?> Units</span>
            </div>
            
            <div class="info-item">
                <strong>Requested by:</strong>
                <span>
                    <?php if ($request_count > 0): ?>
                        <?= $request_count ?> person(s)
                    <?php else: ?>
                        None yet
                    <?php endif; ?>
                </span>
            </div>
            
            <div class="info-item">
                <strong>Delivered:</strong>
                <span class="status-badge <?= $donation['delivered_at'] ? 'status-completed' : 'status-pending' ?>">
                    <?= $donation['delivered_at'] ? 'Delivered' : 'Pending' ?>
                </span>
            </div>
            
            <div class="info-item">
                <strong>Status:</strong>
                <span class="status-badge 
                    <?= strtolower($donation['status']) == 'pending' ? 'status-pending' :
                    (strtolower($donation['status']) == 'completed' ? 'status-completed' :
                    (strtolower($donation['status']) == 'available' ? 'status-available' : 'status-pending')) ?>">
                    <?= htmlspecialchars($donation['status']) ?>
                </span>
            </div>
        </div>
        
<!-- Requests Section -->
<div class="requests-section">
    <h4>Requests</h4>

    <?php if ($requests->num_rows > 0): ?>
        <?php while ($r = $requests->fetch_assoc()): ?>
            <div class="request" data-request-id="<?= $r['request_id']; ?>">
                <strong><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></strong>
                <p><?= htmlspecialchars($r['project_name']); ?></p>
                <p class="status-text">Status: <span><?= htmlspecialchars($r['status']); ?></span></p>

                <?php if (strtolower($r['status']) === 'pending'): ?>
                    <button class="approve-btn">Approve</button>
                    <button class="decline-btn">Decline</button>
                <?php else: ?>
                    <button class="approve-btn" disabled><?= ucfirst($r['status']); ?></button>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p>No requests yet.</p>
    <?php endif; ?>
</div>

<!-- Comments Section -->
<div class="comments-section">
    <h4>Comments</h4>

    <?php if ($comments->num_rows > 0): ?>
        <?php while ($c = $comments->fetch_assoc()): ?>
            <div class="comment">
                <strong><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?></strong>
                <p><?= htmlspecialchars($c['comment_text']); ?></p>
                <div class="comment-time"><?= date("M d, Y H:i", strtotime($c['created_at'])); ?></div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p>No comments yet. Be the first to comment!</p>
    <?php endif; ?>


            <?php if (isset($_SESSION['user_id'])): ?>
                <form class="add-comment-form" data-id="<?= $donation_id; ?>">
                    <textarea name="comment_text" placeholder="Write a comment..." required></textarea>
                    <button type="submit">Post Comment</button>
                </form>
            <?php else: ?>
                <p><em>You must be logged in to comment.</em></p>
            <?php endif; ?>
        </div>
        
    </div>
    <?php
    exit;
}

// Add new comment
if ($action === 'add_comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Not logged in"]);
        exit;
    }

    $donation_id = intval($_POST['donation_id'] ?? 0);
    $comment_text = trim($_POST['comment_text'] ?? '');
    $user_id = $_SESSION['user_id'];

    if ($donation_id <= 0 || $comment_text === '') {
        echo json_encode(["status" => "error", "message" => "Invalid input"]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO comments (donation_id, user_id, comment_text) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $donation_id, $user_id, $comment_text);
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to add comment"]);
    }
    exit;
}

// Delete donation (add this if not exists)
if ($action === 'delete_donation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Not logged in"]);
        exit;
    }

    $donation_id = intval($_POST['donation_id'] ?? 0);
    
    // Verify ownership before deletion
    $stmt = $conn->prepare("SELECT donor_id FROM donations WHERE donation_id = ?");
    $stmt->bind_param("i", $donation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donation = $result->fetch_assoc();
    
    if (!$donation || $donation['donor_id'] != $_SESSION['user_id']) {
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit;
    }

    // Delete the donation
    $stmt = $conn->prepare("DELETE FROM donations WHERE donation_id = ?");
    $stmt->bind_param("i", $donation_id);
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to delete donation"]);
    }
    exit;
}

// Approve a donation request
if ($action === 'approve_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Not logged in"]);
        exit;
    }

    $request_id = intval($_POST['request_id'] ?? 0);
    if ($request_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid request id"]);
        exit;
    }

    // Fetch request and donation info
    $stmt = $conn->prepare("
        SELECT dr.request_id, dr.donation_id, dr.status as request_status, d.donor_id, d.quantity AS current_quantity, d.total_quantity
        FROM donation_requests dr
        JOIN donations d ON dr.donation_id = d.donation_id
        WHERE dr.request_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();

    if (!$request) {
        error_log("[donate_process] approve_request: request not found: " . $request_id);
        echo json_encode(["status" => "error", "message" => "Request not found"]);
        exit;
    }

    // only donor who owns the donation can approve
    if ($request['donor_id'] != $_SESSION['user_id']) {
        error_log("[donate_process] approve_request: unauthorized user. request_id: $request_id user:" . ($_SESSION['user_id'] ?? 'none'));
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit;
    }

    // ensure it is pending
    if (strtolower($request['request_status']) !== 'pending') {
        echo json_encode(["status" => "error", "message" => "Request is not pending"]);
        exit;
    }

    // Check stock remaining
    $current = intval($request['current_quantity']);
    $total = intval($request['total_quantity']);
    if ($current >= $total) {
        echo json_encode(["status" => "error", "message" => "No more items remaining"]);
        exit;
    }

    // Update request status
    $stmt = $conn->prepare("UPDATE donation_requests SET status = 'approved' WHERE request_id = ?");
    $stmt->bind_param("i", $request_id);
    if (!$stmt->execute()) {
        error_log("[donate_process] approve_request: failed update request_id=" . $request_id . " - " . $conn->error);
        echo json_encode(["status" => "error", "message" => "Failed to approve request"]);
        exit;
    }

    // Increment donation quantity
    $stmt = $conn->prepare("UPDATE donations SET quantity = quantity + 1 WHERE donation_id = ?");
    $stmt->bind_param("i", $request['donation_id']);
    if (!$stmt->execute()) {
        error_log("[donate_process] approve_request: failed increment donation_id=" . $request['donation_id'] . " - " . $conn->error);
        echo json_encode(["status" => "error", "message" => "Failed to update donation quantity"]);
        exit;
    }

    // fetch new quantity
    $stmt = $conn->prepare("SELECT quantity, total_quantity FROM donations WHERE donation_id = ?");
    $stmt->bind_param("i", $request['donation_id']);
    $stmt->execute();
    $newRow = $stmt->get_result()->fetch_assoc();
    $new_quantity = intval($newRow['quantity']);
    $total_quantity = intval($newRow['total_quantity']);

    echo json_encode([
        "status" => "success",
        "message" => "Request approved",
        "new_quantity" => $new_quantity,
        "total_quantity" => $total_quantity
    ]);
    exit;
}


// Decline a donation request
if ($action === 'decline_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Not logged in"]);
        exit;
    }

    $request_id = intval($_POST['request_id'] ?? 0);
    if ($request_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid request id"]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT dr.request_id, dr.donation_id, dr.status as request_status, d.donor_id
        FROM donation_requests dr
        JOIN donations d ON dr.donation_id = d.donation_id
        WHERE dr.request_id = ?
    ");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();

    if (!$request) {
        error_log("[donate_process] decline_request: request not found: " . $request_id);
        echo json_encode(["status" => "error", "message" => "Request not found"]);
        exit;
    }

    if ($request['donor_id'] != $_SESSION['user_id']) {
        error_log("[donate_process] decline_request: unauthorized user. request_id: $request_id user:" . ($_SESSION['user_id'] ?? 'none'));
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit;
    }

    if (strtolower($request['request_status']) !== 'pending') {
        echo json_encode(["status" => "error", "message" => "Request is not pending"]);
        exit;
    }

    // Update status
    $stmt = $conn->prepare("UPDATE donation_requests SET status = 'declined' WHERE request_id = ?");
    $stmt->bind_param("i", $request_id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Request declined"]);
    } else {
        error_log("[donate_process] decline_request: failed update request_id=" . $request_id . " - " . $conn->error);
        echo json_encode(["status" => "error", "message" => "Failed to decline request"]);
    }
    exit;
}

// Cancel Request
if (isset($_POST['action']) && $_POST['action'] === 'cancel_request') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
        exit;
    }

    $request_id = intval($_POST['request_id'] ?? 0);
    if ($request_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request ID']);
        exit;
    }

    $user_id = $_SESSION['user_id'];

    $conn->begin_transaction();
    try {
        // Fetch request with user ownership check
        $stmt = $conn->prepare("SELECT donation_id, quantity_claim, user_id FROM donation_requests WHERE request_id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $request = $result->fetch_assoc();

        if (!$request || $request['user_id'] != $user_id) {
            throw new Exception("Request not found or unauthorized.");
        }

        $donation_id = intval($request['donation_id']);
        $quantity_claim = intval($request['quantity_claim']);

        // Delete request
        $stmt = $conn->prepare("DELETE FROM donation_requests WHERE request_id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();

        // Return quantity to donation
        if ($quantity_claim > 0) {
            $stmt = $conn->prepare("UPDATE donations SET quantity = quantity + ? WHERE donation_id = ?");
            $stmt->bind_param("ii", $quantity_claim, $donation_id);
            $stmt->execute();
        }

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Request cancelled and quantity returned.']);
    } catch (Exception $e) {
        $conn->rollback();
        error_log("[donate_process] cancel_request failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Failed to cancel request.']);
    }
    exit;
}





// Get request details for editing - ensure delivery_end is included
if (isset($_GET['action']) && $_GET['action'] === 'get_request_details' && isset($_GET['request_id'])) {
    $id = intval($_GET['request_id']);
    $stmt = $conn->prepare("
    SELECT dr.request_id, dr.quantity_claim, dr.urgency_level, dr.requested_at,
           dr.delivery_status, dr.delivery_start, dr.delivery_end,
           dr.pickup_date, dr.sorting_facility_date, dr.in_transit_date, dr.delivered_date,
           d.category, d.subcategory, d.image_path, d.item_name,
           CONCAT(u.first_name, ' ', u.last_name) AS donor_name,
           u.first_name, u.last_name,
           p.project_name
    FROM donation_requests dr
    JOIN donations d ON dr.donation_id = d.donation_id
    LEFT JOIN users u ON d.donor_id = u.user_id
    LEFT JOIN projects p ON dr.project_id = p.project_id
    WHERE dr.request_id = ?
");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        echo json_encode(['status' => 'success', 'data' => $data]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Request not found.']);
    }
    exit;
}



// Update Request (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'update_request') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Not logged in"]);
        exit;
    }

    $request_id = intval($_POST['request_id']);
    $new_quantity_claim = intval($_POST['quantity_claim']);
    $urgency_level = htmlspecialchars(trim($_POST['urgency_level']));
    $user_id = $_SESSION['user_id'];

    // Fetch existing request and donation
    $stmt = $conn->prepare("
        SELECT dr.quantity_claim, dr.donation_id, d.quantity AS donation_quantity, dr.user_id
        FROM donation_requests dr
        JOIN donations d ON dr.donation_id = d.donation_id
        WHERE dr.request_id = ?
    ");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();

    if (!$request || $request['user_id'] != $user_id) {
        echo json_encode(["status" => "error", "message" => "Unauthorized or request not found."]);
        exit;
    }

    $old_quantity_claim = intval($request['quantity_claim']);
    $donation_id = intval($request['donation_id']);
    $available_quantity = intval($request['donation_quantity']);
    $difference = $new_quantity_claim - $old_quantity_claim;

    if ($difference > 0 && $difference > $available_quantity) {
        echo json_encode(["status" => "error", "message" => "Not enough units available. Only $available_quantity left."]);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Adjust donation quantity
        if ($difference != 0) {
            $stmt = $conn->prepare("UPDATE donations SET quantity = quantity - ? WHERE donation_id = ?");
            $stmt->bind_param("ii", $difference, $donation_id);
            $stmt->execute();
        }

        // Update request
        $stmt = $conn->prepare("UPDATE donation_requests SET quantity_claim = ?, urgency_level = ? WHERE request_id = ?");
        $stmt->bind_param("isi", $new_quantity_claim, $urgency_level, $request_id);
        $stmt->execute();

        $conn->commit();
        echo json_encode(["status" => "success", "message" => "Request updated successfully!"]);
    } catch (Exception $e) {
        $conn->rollback();
        error_log("[donate_process] update_request failed: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "Failed to update request."]);
    }
    exit;
}

// Update delivery status - FIXED VERSION with proper date handling
if ($action === 'update_delivery_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Not logged in"]);
        exit;
    }

    $request_id = intval($_POST['request_id'] ?? 0);
    $delivery_status = trim($_POST['delivery_status'] ?? '');
    $estimated_delivery = !empty($_POST['estimated_delivery']) ? $_POST['estimated_delivery'] : null;
    $estimated_delivery_date = !empty($_POST['estimated_delivery_date']) ? $_POST['estimated_delivery_date'] : null;

    if ($request_id <= 0 || empty($delivery_status)) {
        echo json_encode(["status" => "error", "message" => "Invalid input"]);
        exit;
    }

    // Verify ownership
    $stmt = $conn->prepare("
        SELECT dr.request_id, d.donor_id 
        FROM donation_requests dr 
        JOIN donations d ON dr.donation_id = d.donation_id 
        WHERE dr.request_id = ?
    ");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();

    if (!$request || $request['donor_id'] != $_SESSION['user_id']) {
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit;
    }

    // Convert status from frontend format to database format
    $status_conversion = [
        'pending' => 'Pending',
        'waiting_for_pickup' => 'Waiting for Pickup',
        'at_sorting_facility' => 'At Sorting Facility',
        'on_the_way' => 'On the Way',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled'
    ];
    
    $db_delivery_status = $status_conversion[$delivery_status] ?? $delivery_status;

    // Build the update query based on the status
    $update_fields = ["delivery_status = ?"];
    $param_types = "s";
    $param_values = [$db_delivery_status];

    // Handle pickup date and delivery_end ONLY for Waiting for Pickup
    if ($estimated_delivery && $delivery_status === 'waiting_for_pickup') {
        $pickup_date = date('Y-m-d', strtotime($estimated_delivery));
        $update_fields[] = "pickup_date = ?";
        $update_fields[] = "delivery_start = ?"; // Set delivery_start as pickup date
        $param_types .= "ss";
        $param_values[] = $pickup_date;
        $param_values[] = $pickup_date;
        
        // Handle estimated delivery end date - ONLY for Waiting for Pickup
        if ($estimated_delivery_date) {
            $estimated_delivery_date_formatted = date('Y-m-d', strtotime($estimated_delivery_date));
            $update_fields[] = "delivery_end = ?";
            $param_types .= "s";
            $param_values[] = $estimated_delivery_date_formatted;
            
            error_log("[donate_process] Setting delivery_end for Waiting for Pickup: " . $estimated_delivery_date_formatted);
        }
    }

    // Handle other status dates WITHOUT affecting delivery_start/delivery_end
    if ($estimated_delivery && $delivery_status !== 'waiting_for_pickup') {
        $delivery_date = date('Y-m-d', strtotime($estimated_delivery));
        
        // Update specific date field based on status
        switch($delivery_status) {
            case 'at_sorting_facility':
                $update_fields[] = "sorting_facility_date = ?";
                $param_types .= "s";
                $param_values[] = $delivery_date;
                error_log("[donate_process] Setting sorting_facility_date: " . $delivery_date);
                break;
            case 'on_the_way':
                $update_fields[] = "in_transit_date = ?";
                $param_types .= "s";
                $param_values[] = $delivery_date;
                error_log("[donate_process] Setting in_transit_date: " . $delivery_date);
                break;
            case 'delivered':
                $update_fields[] = "delivered_date = ?";
                $param_types .= "s";
                $param_values[] = $delivery_date;
                error_log("[donate_process] Setting delivered_date: " . $delivery_date);
                break;
        }
        
        // IMPORTANT: Do NOT update delivery_start or delivery_end for these statuses
        // They should only be set during Waiting for Pickup phase
    }

    // If we're updating to a status that should clear future dates, do so
    if (in_array($delivery_status, ['pending', 'cancelled'])) {
        // Clear all delivery dates when going back to pending or cancelled
        $update_fields[] = "pickup_date = NULL";
        $update_fields[] = "sorting_facility_date = NULL";
        $update_fields[] = "in_transit_date = NULL";
        $update_fields[] = "delivered_date = NULL";
        $update_fields[] = "delivery_start = NULL";
        $update_fields[] = "delivery_end = NULL";
    }

    $update_fields_sql = implode(", ", $update_fields);
    
    // Debug logging
    error_log("[donate_process] Update query: UPDATE donation_requests SET $update_fields_sql WHERE request_id = $request_id");
    error_log("[donate_process] Status: $db_delivery_status, Estimated: $estimated_delivery, Delivery End: " . ($estimated_delivery_date ?? 'none'));

    $stmt = $conn->prepare("
        UPDATE donation_requests 
        SET $update_fields_sql
        WHERE request_id = ?
    ");
    
    $param_types .= "i";
    $param_values[] = $request_id;
    
    if ($stmt->bind_param($param_types, ...$param_values)) {
        if ($stmt->execute()) {
            // Log the successful update for debugging
            error_log("[donate_process] Delivery status updated successfully - Request: $request_id, Status: $db_delivery_status");
            
            echo json_encode([
                "status" => "success", 
                "message" => "Delivery status updated successfully",
                "delivery_end" => $estimated_delivery_date
            ]);
        } else {
            error_log("[donate_process] Failed to execute update: " . $stmt->error);
            echo json_encode(["status" => "error", "message" => "Failed to update delivery status: " . $stmt->error]);
        }
    } else {
        error_log("[donate_process] Failed to bind parameters: " . $stmt->error);
        echo json_encode(["status" => "error", "message" => "Failed to bind parameters: " . $stmt->error]);
    }
    exit;
}


// Confirm delivery receipt (Requester action)
if ($action === 'confirm_delivery' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Not logged in"]);
        exit;
    }

    $request_id = intval($_POST['request_id'] ?? 0);
    if ($request_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid request id"]);
        exit;
    }

    // Verify requester ownership
    $stmt = $conn->prepare("
        SELECT dr.request_id, dr.user_id, dr.delivery_status
        FROM donation_requests dr
        WHERE dr.request_id = ?
    ");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();

    if (!$request) {
        echo json_encode(["status" => "error", "message" => "Request not found"]);
        exit;
    }

    if ($request['user_id'] != $_SESSION['user_id']) {
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit;
    }

    // Only allow confirmation if status is "On the Way"
    if (strtolower($request['delivery_status']) !== 'on the way') {
        echo json_encode(["status" => "error", "message" => "Can only confirm delivery when item is 'On the Way'"]);
        exit;
    }

    // Update to delivered status
    $stmt = $conn->prepare("
        UPDATE donation_requests 
        SET delivery_status = 'Delivered', delivery_start = COALESCE(delivery_start, CURDATE()), delivery_end = CURDATE()
        WHERE request_id = ?
    ");
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Delivery confirmed successfully!"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to confirm delivery"]);
    }
    exit;
}

// Auto-update cancelled requests
if ($action === 'auto_update_cancelled' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $request_id = intval($_POST['request_id'] ?? 0);
    if ($request_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid request id"]);
        exit;
    }

    // Update to cancelled status (this would typically be called when requester cancels)
    $stmt = $conn->prepare("
        UPDATE donation_requests 
        SET delivery_status = 'Cancelled'
        WHERE request_id = ?
    ");
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Status updated to cancelled"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update status"]);
    }
    exit;
}

?>
