<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

// Ensure logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $request_id = intval($_POST['request_id']);

    // Ensure the request belongs to the user's donations
    $stmt = $conn->prepare("
        DELETE dr FROM donation_requests dr
        JOIN donations d ON dr.donation_id = d.donation_id
        WHERE dr.request_id = ? AND d.donor_id = ?
    ");
    $stmt->bind_param("ii", $request_id, $_SESSION['user_id']);
    $success = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $success]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>
