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
$donation_id = (int)($_POST['donation_id'] ?? 0);
$parent_id = (int)($_POST['parent_id'] ?? 0);
$comment_text = trim($_POST['comment_text'] ?? '');

if (empty($comment_text)) {
    echo json_encode(['success' => false, 'message' => 'Empty reply']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO comments (donation_id, user_id, parent_id, comment_text, created_at) VALUES (?, ?, ?, ?, NOW())");
$stmt->bind_param("iiis", $donation_id, $user_id, $parent_id, $comment_text);

if ($stmt->execute()) {
    $comment_id = $stmt->insert_id;

    $user_query = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $user_query->bind_param("i", $user_id);
    $user_query->execute();
    $user_result = $user_query->get_result()->fetch_assoc();

    echo json_encode([
        'success' => true,
        'comment_id' => $comment_id,
        'first_name' => $user_result['first_name'],
        'last_name' => $user_result['last_name'],
        'comment_text' => $comment_text,
        'created_at' => date('Y-m-d H:i:s')
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
