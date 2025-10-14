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

$material_id = isset($_POST['material_id']) ? (int)$_POST['material_id'] : 0;
if ($material_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid material']);
    exit();
}

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit();
}

$uploaddir = __DIR__ . '/assets/uploads/materials/';
if (!is_dir($uploaddir)) mkdir($uploaddir, 0755, true);

$fname = basename($_FILES['photo']['name']);
$ext = pathinfo($fname, PATHINFO_EXTENSION);
$allowed = ['jpg','jpeg','png','gif','webp'];
if (!in_array(strtolower($ext), $allowed)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid file type']);
    exit();
}

$target = $uploaddir . uniqid('mat_') . '.' . $ext;
if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    exit();
}

// ensure table exists
try {
    $conn = getDBConnection();
    $conn->query("CREATE TABLE IF NOT EXISTS material_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        material_id INT NOT NULL,
        project_id INT DEFAULT NULL,
        photo_path VARCHAR(255) NOT NULL,
        uploaded_by INT DEFAULT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // attempt to determine project_id from material
    $pid = null;
    $mstmt = $conn->prepare("SELECT project_id FROM project_materials WHERE material_id = ?");
    $mstmt->bind_param('i', $material_id);
    $mstmt->execute();
    $mres = $mstmt->get_result();
    if ($mr = $mres->fetch_assoc()) $pid = (int)$mr['project_id'];

    // Enforce one photo per material: remove existing photo record & file if present
    try {
        $old = $conn->prepare("SELECT id, photo_path FROM material_photos WHERE material_id = ? LIMIT 1");
        if ($old) {
            $old->bind_param('i', $material_id);
            $old->execute();
            $ores = $old->get_result();
            if ($or = $ores->fetch_assoc()) {
                // attempt to unlink the old file
                $oldPath = __DIR__ . '/' . $or['photo_path'];
                if (is_file($oldPath)) @unlink($oldPath);
                $del = $conn->prepare("DELETE FROM material_photos WHERE id = ?");
                if ($del) { $del->bind_param('i', $or['id']); $del->execute(); }
            }
        }
    } catch (Exception $e) { /* ignore */ }

    $in = $conn->prepare("INSERT INTO material_photos (material_id, project_id, photo_path, uploaded_by) VALUES (?, ?, ?, ?)");
    $userId = $_SESSION['user_id'];
    $relpath = 'assets/uploads/materials/' . basename($target);
    $in->bind_param('iisi', $material_id, $pid, $relpath, $userId);
    $in->execute();
    $newId = $conn->insert_id;

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'path' => $relpath, 'id' => $newId]);
    exit();
} catch (Exception $e) {
    error_log('upload_material_photo error: ' . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit();
}
