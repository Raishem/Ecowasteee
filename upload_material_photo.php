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
$photo_type = isset($_POST['photo_type']) ? strtolower(trim($_POST['photo_type'])) : 'after';
if (!in_array($photo_type, ['before','after'], true)) $photo_type = 'after';
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
            photo_type VARCHAR(16) DEFAULT 'after',
        uploaded_by INT DEFAULT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Defensive: if the table existed before and lacks the photo_type column, add it.
        try {
            $colCheck = $conn->query("SHOW COLUMNS FROM material_photos LIKE 'photo_type'");
            if ($colCheck && $colCheck->num_rows === 0) {
                $conn->query("ALTER TABLE material_photos ADD COLUMN photo_type VARCHAR(16) DEFAULT 'after'");
            }
        } catch (Exception $e) {
            // ignore - we'll surface insert errors below instead
        }

    // attempt to determine project_id from material
    $pid = null;
    $mstmt = $conn->prepare("SELECT project_id FROM project_materials WHERE material_id = ?");
    if ($mstmt) {
        $mstmt->bind_param('i', $material_id);
        $mstmt->execute();
        $mres = $mstmt->get_result();
        if ($mres) {
            if ($mr = $mres->fetch_assoc()) $pid = (int)$mr['project_id'];
        }
    }

    // Enforce per-type limit: allow up to 5 photos per material per photo_type
    try {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM material_photos WHERE material_id = ? AND LOWER(photo_type) = ?");
        if ($countStmt) {
            $lcType = $photo_type;
            $countStmt->bind_param('is', $material_id, $lcType);
            $countStmt->execute();
            $cresObj = $countStmt->get_result();
            $cRes = $cresObj ? $cresObj->fetch_assoc() : null;
            $existingCount = isset($cRes['c']) ? (int)$cRes['c'] : 0;
        } else {
            $existingCount = 0;
        }
        if ($existingCount >= 5) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Maximum of 5 ' . $photo_type . ' photos allowed for this material']);
            exit();
        }
    } catch (Exception $e) { /* ignore and continue */ }

    $in = $conn->prepare("INSERT INTO material_photos (material_id, project_id, photo_path, photo_type, uploaded_by) VALUES (?, ?, ?, ?, ?)");
    $userId = (int) $_SESSION['user_id'];
    $relpath = 'assets/uploads/materials/' . basename($target);
    $pid_int = $pid ? (int)$pid : 0;
    if (!$in) {
        throw new Exception('DB prepare failed: ' . $conn->error);
    }
    // types: i (material_id), i (project_id), s (photo_path), s (photo_type), i (uploaded_by)
    if (!$in->bind_param('iissi', $material_id, $pid_int, $relpath, $photo_type, $userId)) {
        throw new Exception('bind_param failed: ' . $in->error);
    }
    if (!$in->execute()) {
        throw new Exception('INSERT failed: ' . $in->error);
    }
    $newId = $conn->insert_id;

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'path' => $relpath, 'id' => $newId]);
    exit();
} catch (Exception $e) {
    error_log('upload_material_photo error: ' . $e->getMessage());
    header('Content-Type: application/json');
    $isLocal = in_array(@$_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']) || (@$_SERVER['SERVER_NAME'] === 'localhost');
    $msg = $isLocal ? ('Server error: ' . $e->getMessage()) : 'Server error';
    echo json_encode(['success' => false, 'message' => $msg]);
    exit();
}
