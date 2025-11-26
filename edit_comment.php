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
$new_content = trim($_POST['content'] ?? '');

if ($comment_id <= 0 || $new_content === '') {
    echo json_encode(['success' => false, 'message' => 'Empty or invalid data']);
    exit;
}

// Secure update — only allow editing own comments
$stmt = $conn->prepare("UPDATE comments SET comment_text = ?, updated_at = NOW() WHERE comment_id = ? AND user_id = ?");
$stmt->bind_param("sii", $new_content, $comment_id, $user_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Comment updated successfully',
        'new_text' => htmlspecialchars($new_content)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Edit failed or no permission'
    ]);
}
