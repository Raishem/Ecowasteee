<?php
require_once 'config.php';
session_start();

// Get share token
$token = isset($_GET['token']) ? $_GET['token'] : '';
if (empty($token)) {
    header('Location: index.php');
    exit;
}

$conn = getDBConnection();

try {
    // Get project details
    $stmt = $conn->prepare("
        SELECT p.*, ps.share_token, u.username 
        FROM projects p
        INNER JOIN project_shares ps ON p.project_id = ps.project_id
        INNER JOIN users u ON p.user_id = u.user_id
        WHERE ps.share_token = ?
    ");
    $stmt->execute([$token]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        header('Location: index.php');
        exit;
    }

    // Get project materials
    $materials_stmt = $conn->prepare("
        SELECT * FROM project_materials 
        WHERE project_id = ?
    ");
    $materials_stmt->execute([$project['project_id']]);
    $materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get project steps with photos
    $steps_stmt = $conn->prepare("
        SELECT s.*, GROUP_CONCAT(sp.photo_path) as photos
        FROM project_steps s
        LEFT JOIN step_photos sp ON s.step_id = sp.step_id
        WHERE s.project_id = ?
        GROUP BY s.step_id
        ORDER BY s.step_number
    ");
    $steps_stmt->execute([$project['project_id']]);
    $steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Log error and redirect
    error_log("Database error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($project['project_name']) ?> - EcoWaste Project</title>
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/projects.css">
    <style>
        .shared-project-banner {
            background-color: #4CAF50;
            color: white;
            padding: 10px;
            text-align: center;
            margin-bottom: 20px;
        }
        .project-creator {
            font-style: italic;
            color: #666;
            margin-bottom: 15px;
        }
        .steps-container {
            margin-top: 30px;
        }
        .step-photos {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .step-photo {
            max-width: 200px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <div class="shared-project-banner">
            Shared Project
        </div>

        <h1><?= htmlspecialchars($project['project_name']) ?></h1>
        <div class="project-creator">
            Created by <?= htmlspecialchars($project['username']) ?>
        </div>

        <div class="project-description">
            <h3>Description</h3>
            <p><?= nl2br(htmlspecialchars($project['description'])) ?></p>
        </div>

        <div class="materials-list">
            <h3>Required Materials</h3>
            <ul>
                <?php foreach ($materials as $material): ?>
                <li>
                    <?= htmlspecialchars($material['material_name']) ?> 
                    - <?= htmlspecialchars($material['quantity']) ?> 
                    <?= htmlspecialchars($material['unit']) ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="steps-container">
            <h3>Project Steps</h3>
            <?php foreach ($steps as $step): ?>
            <div class="step-card">
                <h4>Step <?= htmlspecialchars($step['step_number']) ?>: <?= htmlspecialchars($step['title']) ?></h4>
                <p><?= nl2br(htmlspecialchars($step['instructions'])) ?></p>
                <?php if (!empty($step['photos'])): ?>
                <div class="step-photos">
                    <?php foreach (explode(',', $step['photos']) as $photo): ?>
                    <img src="assets/uploads/<?= htmlspecialchars($photo) ?>" 
                         alt="Step <?= htmlspecialchars($step['step_number']) ?> photo"
                         class="step-photo">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($project['status'] === 'completed'): ?>
        <div class="completion-info">
            <h3>Project Completed!</h3>
            <p>This project was completed on <?= date('F j, Y', strtotime($project['completion_date'])) ?></p>
        </div>
        <?php endif; ?>

        <div class="actions">
            <a href="projects.php" class="button">View All Projects</a>
            <?php if (!isset($_SESSION['user_id'])): ?>
            <a href="signup.php" class="button primary">Sign Up to Create Your Own Project</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Add any required JavaScript here
    </script>
</body>
</html>