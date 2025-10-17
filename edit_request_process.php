<?php
// ==========================================
// edit_request_process.php
// Purpose: Update an existing donation request
// Returns a JSON response for SweetAlert
// ==========================================

session_start();
require_once 'config.php';
header('Content-Type: application/json');

// STEP 1: Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized access. Please log in again."
    ]);
    exit();
}

$conn = getDBConnection();

// STEP 2: Validate incoming POST data
if (empty($_POST['request_id']) || empty($_POST['quantity_claim']) || empty($_POST['urgency_level'])) {
    echo json_encode([
        "status" => "error",
        "message" => "All fields are required."
    ]);
    exit();
}

$request_id = intval($_POST['request_id']);
$quantity_claim = intval($_POST['quantity_claim']);
$urgency_level = htmlspecialchars(trim($_POST['urgency_level']));
$user_id = $_SESSION['user_id'];

// STEP 3: Verify that this request belongs to the logged-in user
$check = $conn->prepare("SELECT user_id FROM donation_requests WHERE request_id = ?");
$check->bind_param("i", $request_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Request not found."
    ]);
    exit();
}

$row = $result->fetch_assoc();
if ($row['user_id'] != $user_id) {
    echo json_encode([
        "status" => "error",
        "message" => "You are not authorized to edit this request."
    ]);
    exit();
}

// STEP 4: Perform the update
$stmt = $conn->prepare("
    UPDATE donation_requests 
    SET quantity_claim = ?, urgency_level = ? 
    WHERE request_id = ?
");
$stmt->bind_param("isi", $quantity_claim, $urgency_level, $request_id);

if ($stmt->execute()) {
    // ✅ STEP 5: Send success response (handled by SweetAlert)
    echo json_encode([
        "status" => "success",
        "message" => "Request updated successfully!"
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Database update failed. Please try again."
    ]);
}

// STEP 6: Close connections
$stmt->close();
$conn->close();
?>
