<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['status'])) {
    $request_id = intval($_POST['request_id']);
    $status = $_POST['status'];

    // Only allow approved or declined
    if (!in_array($status, ['approved', 'declined'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE donation_requests SET status = ? WHERE request_id = ?");
    $stmt->bind_param("si", $status, $request_id);
    $success = $stmt->execute();
    $stmt->close();

    echo json_encode([
        'success' => $success,
        'status' => $status
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
exit;
?>
