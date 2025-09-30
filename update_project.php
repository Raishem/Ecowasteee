<?php
session_start();
require_once 'config.php';

// Set JSON content type for all responses
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$conn = getDBConnection();

// Handle GET requests for project details
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_project_details') {
    $project_id = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT);
    
    if (!$project_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid project ID']);
        exit;
    }

    try {
        // Get project details
        $stmt = $conn->prepare("
            SELECT p.*, 
                   pm.material_id,
                   pm.material_name,
                   pm.quantity,
                   pm.unit,
                   pm.is_found,
                   COALESCE(p.status, 'planning') as status
            FROM projects p
            LEFT JOIN project_materials pm ON p.project_id = pm.project_id
            WHERE p.project_id = ? AND p.user_id = ?
        ");
        $stmt->execute([$project_id, $_SESSION['user_id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo json_encode(['success' => false, 'message' => 'Project not found']);
            exit;
        }

        // Get project steps
        $steps_stmt = $conn->prepare("
            SELECT ps.*, GROUP_CONCAT(sp.photo_path) as photos
            FROM project_steps ps
            LEFT JOIN step_photos sp ON ps.step_id = sp.step_id
            WHERE ps.project_id = ?
            GROUP BY ps.step_id
            ORDER BY ps.step_number
        ");
        $steps_stmt->execute([$project_id]);
        $steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format the response
        $project = [
            'project_id' => $rows[0]['project_id'],
            'project_name' => $rows[0]['project_name'],
            'status' => $rows[0]['status'],
            'description' => $rows[0]['description'],
            'created_at' => $rows[0]['created_at']
        ];

        $materials = [];
        foreach ($rows as $row) {
            if ($row['material_id']) {
                $materials[] = [
                    'id' => $row['material_id'],
                    'name' => $row['material_name'],
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit']
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'project' => $project,
            'materials' => $materials,
            'steps' => $steps
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle POST requests for updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['action']) || !isset($_POST['project_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit;
    }

    $project_id = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
    if (!$project_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid project ID']);
        exit;
    }

    // Verify project belongs to user
    $check_query = $conn->prepare("SELECT user_id FROM projects WHERE project_id = ?");
    $check_query->execute([$project_id]);
    $project = $check_query->fetch();

    if (!$project || $project['user_id'] !== $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // Handle different actions
    switch ($_POST['action']) {
        case 'add_material':
            if (!isset($_POST['name']) || !isset($_POST['unit'])) {
                echo json_encode(['success' => false, 'message' => 'Missing material details']);
                exit;
            }

            try {
                $material_stmt = $conn->prepare("
                    INSERT INTO project_materials (project_id, material_name, unit, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $material_stmt->execute([
                    $project_id,
                    $_POST['name'],
                    $_POST['unit']
                ]);

                echo json_encode(['success' => true, 'material_id' => $conn->lastInsertId()]);
                exit;

            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                exit;
            }
            break;

        case 'add_step':
            // Validate required fields
            if (!isset($_POST['title']) || !isset($_POST['instructions'])) {
                echo json_encode(['success' => false, 'message' => 'Missing step details']);
                exit;
            }

            try {
                $conn->beginTransaction();

                // Get next step number
                $step_num_stmt = $conn->prepare("
                    SELECT COALESCE(MAX(step_number), 0) + 1 as next_step
                    FROM project_steps
                    WHERE project_id = ?
                ");
                $step_num_stmt->execute([$project_id]);
                $next_step = $step_num_stmt->fetch()['next_step'];

                // Insert step
                $step_stmt = $conn->prepare("
                    INSERT INTO project_steps (project_id, step_number, title, instructions)
                    VALUES (?, ?, ?, ?)
                ");
                $step_stmt->execute([
                    $project_id,
                    $next_step,
                    $_POST['title'],
                    $_POST['instructions']
                ]);
                $step_id = $conn->lastInsertId();

                // Handle photo uploads
                if (!empty($_FILES['photos']['name'][0])) {
                    $upload_dir = 'assets/uploads/';
                    foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                        $file_name = uniqid() . '_' . basename($_FILES['photos']['name'][$key]);
                        $target_file = $upload_dir . $file_name;

                        if (move_uploaded_file($tmp_name, $target_file)) {
                            $photo_stmt = $conn->prepare("
                                INSERT INTO step_photos (step_id, photo_path)
                                VALUES (?, ?)
                            ");
                            $photo_stmt->execute([$step_id, $file_name]);
                        }
                    }
                }

                $conn->commit();
                echo json_encode(['success' => true, 'step_id' => $step_id]);
                exit;

            } catch (PDOException $e) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                exit;
            }
            break;

        case 'update_project_status':
            if (!isset($_POST['status'])) {
                echo json_encode(['success' => false, 'message' => 'Missing status']);
                exit;
            }

            $allowed_statuses = ['planning', 'collecting', 'in_progress', 'completed'];
            if (!in_array($_POST['status'], $allowed_statuses)) {
                echo json_encode(['success' => false, 'message' => 'Invalid status']);
                exit;
            }

            try {
                $status_stmt = $conn->prepare("
                    UPDATE projects 
                    SET status = ?, 
                        completion_date = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END,
                        updated_at = NOW()
                    WHERE project_id = ?
                ");
                $status_stmt->execute([$_POST['status'], $_POST['status'], $project_id]);

                break;

            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                exit;
            }
            break;

        case 'mark_material_found':
            if (!isset($_POST['material_id'])) {
                echo json_encode(['success' => false, 'message' => 'Missing material ID']);
                exit;
            }

            try {
                $material_stmt = $conn->prepare("
                    UPDATE project_materials 
                    SET is_found = TRUE 
                    WHERE project_id = ? AND material_id = ?
                ");
                $material_stmt->execute([$project_id, $_POST['material_id']]);

                echo json_encode(['success' => true]);
                exit;

            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                exit;
            }
            break;

        case 'generate_share_link':
            try {
                $share_token = bin2hex(random_bytes(16));
                
                $share_stmt = $conn->prepare("
                    INSERT INTO project_shares (project_id, share_token, created_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE share_token = VALUES(share_token)
                ");
                $share_stmt->execute([$project_id, $share_token]);

                $share_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
                            . "://$_SERVER[HTTP_HOST]/shared_project.php?token=" . $share_token;

                echo json_encode(['success' => true, 'share_url' => $share_url]);
                exit;

            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error generating share link']);
                exit;
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }

    try {
        switch ($_POST['action']) {
            case 'update_title':
                $title = trim(filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING));
                if (empty($title)) {
                    throw new Exception('Title is required');
                }
                $stmt = $conn->prepare("UPDATE projects SET project_name = ? WHERE project_id = ?");
                $stmt->execute([$title, $project_id]);
                break;

            case 'update_description':
                $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING));
                if (empty($description)) {
                    throw new Exception('Description is required');
                }
                $stmt = $conn->prepare("UPDATE projects SET description = ? WHERE project_id = ?");
                $stmt->execute([$description, $project_id]);
                break;

            case 'add_material':
                $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING));
                $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
                $unit = trim(filter_input(INPUT_POST, 'unit', FILTER_SANITIZE_STRING));

                if (empty($name) || empty($unit) || !$quantity) {
                    throw new Exception('Invalid material data');
                }

                $stmt = $conn->prepare("
                    INSERT INTO project_materials (project_id, material_name, quantity, unit)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$project_id, $name, $quantity, $unit]);
                break;

            case 'delete_material':
                $material_id = filter_input(INPUT_POST, 'material_id', FILTER_VALIDATE_INT);
                if (!$material_id) {
                    throw new Exception('Invalid material ID');
                }

                $stmt = $conn->prepare("
                    DELETE FROM project_materials 
                    WHERE material_id = ? AND project_id = ?
                ");
                $stmt->execute([$material_id, $project_id]);
                break;

            default:
                throw new Exception('Invalid action');
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}