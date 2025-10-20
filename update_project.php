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
                   COALESCE(p.status, 'collecting') as status
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

            $allowed_statuses = ['collecting', 'in_progress', 'completed'];
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

        case 'toggle_step':
            // toggle or set a step as done/undone. Enforce sequential progression.
            $step_id = filter_input(INPUT_POST, 'step_id', FILTER_VALIDATE_INT);
            $set_done = isset($_POST['done']) ? (int)$_POST['done'] : 1;
            if (!$step_id) {
                echo json_encode(['success' => false, 'message' => 'Missing step id']);
                exit;
            }

            try {
                // Best-effort: create progress table if not exists
                $conn->exec("CREATE TABLE IF NOT EXISTS project_step_progress (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    step_id INT NOT NULL,
                    is_done TINYINT(1) DEFAULT 0,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Get step info (number and project_id)
                $s_stmt = $conn->prepare("SELECT project_id, step_number FROM project_steps WHERE step_id = ?");
                $s_stmt->execute([$step_id]);
                $sinfo = $s_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sinfo) {
                    echo json_encode(['success' => false, 'message' => 'Step not found']);
                    exit;
                }

                $projectIdFromStep = (int)$sinfo['project_id'];
                $stepNumber = (int)$sinfo['step_number'];

                // Ensure the step belongs to the project provided in POST
                if ($projectIdFromStep !== $project_id) {
                    echo json_encode(['success' => false, 'message' => 'Step does not belong to this project']);
                    exit;
                }

                // Enforce progression: if marking as done, previous step must be done
                if ($set_done) {
                    if ($stepNumber > 1) {
                        $prev_stmt = $conn->prepare("SELECT psp.is_done FROM project_step_progress psp
                            JOIN project_steps ps ON ps.step_id = psp.step_id
                            WHERE ps.project_id = ? AND ps.step_number = ?");
                        $prev_stmt->execute([$projectIdFromStep, $stepNumber - 1]);
                        $prev = $prev_stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$prev || !(int)$prev['is_done']) {
                            echo json_encode(['success' => false, 'message' => 'Complete previous step first']);
                            exit;
                        }
                    }
                }

                // Insert or update progress
                $up_stmt = $conn->prepare("SELECT id FROM project_step_progress WHERE project_id = ? AND step_id = ?");
                $up_stmt->execute([$projectIdFromStep, $step_id]);
                $exists = $up_stmt->fetch(PDO::FETCH_ASSOC);
                if ($exists) {
                    $u2 = $conn->prepare("UPDATE project_step_progress SET is_done = ? WHERE id = ?");
                    $u2->execute([$set_done, $exists['id']]);
                } else {
                    $i2 = $conn->prepare("INSERT INTO project_step_progress (project_id, step_id, is_done) VALUES (?, ?, ?)");
                    $i2->execute([$projectIdFromStep, $step_id, $set_done]);
                }

                echo json_encode(['success' => true]);
                exit;

            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                exit;
            }
            break;

        case 'publish_shared_project':
            // Snapshot project into shared_* tables (requires migration run)
            try {
                $conn->beginTransaction();

                // Fetch project data (include status to enforce publish rule)
                $pstmt = $conn->prepare("SELECT project_name, description, cover_photo, tags, status FROM projects WHERE project_id = ?");
                $pstmt->execute([$project_id]);
                $pdata = $pstmt->fetch(PDO::FETCH_ASSOC);
                if (!$pdata) {
                    throw new Exception('Project not found');
                }

                // Only allow publishing completed projects
                if (!isset($pdata['status']) || $pdata['status'] !== 'completed') {
                    throw new Exception('Only completed projects can be shared');
                }

                // Insert into shared_projects
                $ins = $conn->prepare("INSERT INTO shared_projects (project_id, user_id, title, description, cover_photo, tags, privacy, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'public', NOW())");
                $ins->execute([
                    $project_id,
                    $_SESSION['user_id'],
                    $pdata['project_name'],
                    $pdata['description'],
                    $pdata['cover_photo'] ?? null,
                    $pdata['tags'] ?? null
                ]);
                $shared_id = $conn->lastInsertId();

                // Copy materials
                $mstmt = $conn->prepare("SELECT material_name, quantity, unit, is_found FROM project_materials WHERE project_id = ?");
                $mstmt->execute([$project_id]);
                while ($m = $mstmt->fetch(PDO::FETCH_ASSOC)) {
                    $extra = json_encode(['unit' => $m['unit'], 'is_found' => (int)$m['is_found']]);
                    $c = $conn->prepare("INSERT INTO shared_materials (shared_id, name, quantity, extra) VALUES (?, ?, ?, ?)");
                    $c->execute([$shared_id, $m['material_name'], $m['quantity'], $extra]);
                }

                // Copy steps and photos
                $sstmt = $conn->prepare("SELECT step_id, step_number, title, instructions, IFNULL(is_done,0) as is_done FROM project_steps WHERE project_id = ? ORDER BY step_number");
                $sstmt->execute([$project_id]);
                $step_map = [];
                while ($s = $sstmt->fetch(PDO::FETCH_ASSOC)) {
                    $insStep = $conn->prepare("INSERT INTO shared_steps (shared_id, step_number, title, instructions, is_done) VALUES (?, ?, ?, ?, ?)");
                    $insStep->execute([$shared_id, $s['step_number'], $s['title'], $s['instructions'], $s['is_done']]);
                    $new_step_id = $conn->lastInsertId();
                    $step_map[$s['step_id']] = $new_step_id;

                    // copy photos
                    $ph = $conn->prepare("SELECT photo_path FROM step_photos WHERE step_id = ?");
                    $ph->execute([$s['step_id']]);
                    while ($row = $ph->fetch(PDO::FETCH_ASSOC)) {
                        $insP = $conn->prepare("INSERT INTO shared_step_photos (step_id, path) VALUES (?, ?)");
                        $insP->execute([$new_step_id, $row['photo_path']]);
                    }
                }

                // Record an activity that the project was published
                $a = $conn->prepare("INSERT INTO shared_activities (shared_id, user_id, activity_type, data, created_at) VALUES (?, ?, 'publish', ?, NOW())");
                $a->execute([$shared_id, $_SESSION['user_id'], json_encode(['project_id' => $project_id])]);

                $conn->commit();

                $share_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
                    . "://$_SERVER[HTTP_HOST]/shared_project.php?id=" . $shared_id;

                echo json_encode(['success' => true, 'shared_id' => $shared_id, 'share_url' => $share_url]);
                exit;
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            break;

        case 'shared_activity':
            // Record like/comment activities on a shared project
            $shared_id = filter_input(INPUT_POST, 'shared_id', FILTER_VALIDATE_INT);
            $atype = isset($_POST['activity_type']) ? $_POST['activity_type'] : null;
            if (!$shared_id || !$atype) {
                echo json_encode(['success' => false, 'message' => 'Missing parameters']);
                exit;
            }

            try {
                if ($atype === 'like') {
                    // toggle like
                    $likeStmt = $conn->prepare("SELECT id FROM shared_likes WHERE shared_id = ? AND user_id = ?");
                    $likeStmt->execute([$shared_id, $_SESSION['user_id']]);
                    $exists = $likeStmt->fetch(PDO::FETCH_ASSOC);
                    if ($exists) {
                        $d = $conn->prepare("DELETE FROM shared_likes WHERE id = ?");
                        $d->execute([$exists['id']]);
                        $liked = false;
                    } else {
                        $i = $conn->prepare("INSERT INTO shared_likes (shared_id, user_id) VALUES (?, ?)");
                        $i->execute([$shared_id, $_SESSION['user_id']]);
                        $liked = true;
                    }
                    $conn->prepare("INSERT INTO shared_activities (shared_id, user_id, activity_type, data) VALUES (?, ?, 'like', ?)")
                        ->execute([$shared_id, $_SESSION['user_id'], json_encode(['liked' => $liked])]);
                    echo json_encode(['success' => true, 'liked' => $liked]);
                    exit;
                }

                if ($atype === 'comment') {
                    $comment = trim(filter_input(INPUT_POST, 'comment', FILTER_SANITIZE_STRING));
                    if (empty($comment)) {
                        echo json_encode(['success' => false, 'message' => 'Empty comment']);
                        exit;
                    }
                    $cstmt = $conn->prepare("INSERT INTO shared_comments (shared_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
                    $cstmt->execute([$shared_id, $_SESSION['user_id'], $comment]);

                    $conn->prepare("INSERT INTO shared_activities (shared_id, user_id, activity_type, data) VALUES (?, ?, 'comment', ?)")
                        ->execute([$shared_id, $_SESSION['user_id'], json_encode(['comment_id' => $conn->lastInsertId()])]);

                    echo json_encode(['success' => true, 'comment_id' => $conn->lastInsertId()]);
                    exit;
                }

                echo json_encode(['success' => false, 'message' => 'Unknown activity type']);
                exit;
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            break;

        case 'unpublish_shared_project':
            $shared_id = filter_input(INPUT_POST, 'shared_id', FILTER_VALIDATE_INT);
            if (!$shared_id) {
                echo json_encode(['success' => false, 'message' => 'Missing shared id']);
                exit;
            }

            try {
                // Verify ownership
                $s = $conn->prepare("SELECT user_id FROM shared_projects WHERE shared_id = ?");
                $s->execute([$shared_id]);
                $owner = $s->fetch(PDO::FETCH_ASSOC);
                if (!$owner || $owner['user_id'] != $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                    exit;
                }

                $d = $conn->prepare("DELETE FROM shared_projects WHERE shared_id = ?");
                $d->execute([$shared_id]);
                echo json_encode(['success' => true]);
                exit;
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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