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





// Get request details for editing
if ($_GET['action'] === 'get_request_details' && isset($_GET['request_id'])) {
    $id = intval($_GET['request_id']);
    $stmt = $conn->prepare("
    SELECT dr.request_id, dr.quantity_claim, dr.urgency_level, dr.requested_at,
           d.category, d.subcategory, d.image_path, 
           d.delivery_start, d.delivery_end,
           CONCAT(u.first_name, ' ', u.last_name) AS donor_name,
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


?>
