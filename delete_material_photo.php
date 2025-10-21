<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}
$photo_id = isset($_POST['photo_id']) ? (int)$_POST['photo_id'] : 0;
if ($photo_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid photo id']);
    exit();
}
try {
    $conn = getDBConnection();
    $pstmt = $conn->prepare("SELECT photo_path, material_id FROM material_photos WHERE id = ? LIMIT 1");
    $pstmt->bind_param('i', $photo_id);
    $pstmt->execute();
    $pres = $pstmt->get_result();
    if ($row = $pres->fetch_assoc()) {
        $path = __DIR__ . '/' . $row['photo_path'];
        if (is_file($path)) @unlink($path);
        $d = $conn->prepare("DELETE FROM material_photos WHERE id = ?");
        $d->bind_param('i', $photo_id);
        $d->execute();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'material_id' => (int)$row['material_id']]);
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Photo not found']);
        exit();
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit();
}
