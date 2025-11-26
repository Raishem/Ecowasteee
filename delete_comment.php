<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$comment_id = (int)($_POST['id'] ?? 0);

if ($comment_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid comment ID']);
    exit;
}

// First verify ownership
$check = $conn->prepare("SELECT user_id FROM comments WHERE comment_id = ?");
$check->bind_param("i", $comment_id);
$check->execute();
$result = $check->get_result();
$row = $result->fetch_assoc();

if (!$row || (int)$row['user_id'] !== $user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized to delete this comment']);
    exit;
}

// Delete the comment and its replies
$conn->query("DELETE FROM comments WHERE parent_id = $comment_id");

$stmt = $conn->prepare("DELETE FROM comments WHERE comment_id = ?");
$stmt->bind_param("i", $comment_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Comment deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Delete failed']);
}
