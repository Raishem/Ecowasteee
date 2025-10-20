<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized access. Please log in again."]);
    exit();
}

$conn = getDBConnection();

if (empty($_POST['request_id']) || empty($_POST['quantity_claim']) || empty($_POST['urgency_level'])) {
    echo json_encode(["status" => "error", "message" => "All fields are required."]);
    exit();
}

$request_id = intval($_POST['request_id']);
$new_quantity_claim = intval($_POST['quantity_claim']);
$urgency_level = htmlspecialchars(trim($_POST['urgency_level']));
$user_id = $_SESSION['user_id'];

// Fetch existing request
$stmt = $conn->prepare("
    SELECT dr.quantity_claim, dr.donation_id, d.quantity AS donation_quantity
    FROM donation_requests dr
    JOIN donations d ON dr.donation_id = d.donation_id
    WHERE dr.request_id = ? AND dr.user_id = ?
");
$stmt->bind_param("ii", $request_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "Request not found or you are not authorized."]);
    exit();
}

$request = $result->fetch_assoc();
$old_quantity_claim = intval($request['quantity_claim']);
$donation_id = intval($request['donation_id']);
$available_quantity = intval($request['donation_quantity']);

// Calculate difference
$difference = $new_quantity_claim - $old_quantity_claim;
if ($difference > 0 && $difference > $available_quantity) {
    echo json_encode(["status" => "error", "message" => "Not enough units available. Only $available_quantity left."]);
    exit();
}

// Transaction
$conn->begin_transaction();
try {
    if ($difference != 0) {
        $stmt = $conn->prepare("UPDATE donations SET quantity = quantity - ? WHERE donation_id = ?");
        $stmt->bind_param("ii", $difference, $donation_id);
        $stmt->execute();
    }

    $stmt = $conn->prepare("UPDATE donation_requests SET quantity_claim = ?, urgency_level = ? WHERE request_id = ?");
    $stmt->bind_param("isi", $new_quantity_claim, $urgency_level, $request_id);
    $stmt->execute();

    $conn->commit();
    echo json_encode(["status" => "success", "message" => "Request updated successfully!"]);
} catch (Exception $e) {
    $conn->rollback();
    error_log("[edit_request_process] Transaction failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Failed to update request. Please try again."]);
}

$conn->close();
?>
