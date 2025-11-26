<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if project ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: projects.php');
    exit();
}

$project_id = (int)$_GET['id'];

// Handle "Mark as Completed" form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    $current_time = date('Y-m-d H:i:s'); // Current timestamp
    $update_stmt = $conn->prepare("UPDATE projects SET status = 'Completed', completed_at = ? WHERE project_id = ? AND user_id = ?");
    $update_stmt->bind_param("sii", $current_time, $project_id, $user_id);
    $update_stmt->execute();
    header("Location: project_detail.php?id=$project_id");
    exit();
}

// Fetch project from database
$stmt = $conn->prepare("SELECT * FROM projects WHERE project_id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$project = $result->fetch_assoc();

if (!$project) {
    // Project not found or does not belong to user
    header('Location: projects.php');
    exit();
}

// Helper function for elapsed time
function time_elapsed($datetime) {
    $now = new DateTime();
    $date = new DateTime($datetime);
    $diff = $now->diff($date);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/projects.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .back-btn {
            display: inline-block;
            margin: 20px;
            text-decoration: none;
            color: #2e8b57;
            font-weight: bold;
        }
        .mark-completed-btn {
            background-color: #2e8b57;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-top: 15px;
        }
        .mark-completed-btn:disabled {
            background-color: gray;
            cursor: not-allowed;
        }
        .project-card {
            max-width: 600px;
            margin: 30px auto;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 10px;
            background-color: #f7f7f7;
        }
        .project-card p { margin: 5px 0; }
    </style>
</head>
<body>
    <header>
        <a href="projects.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Projects</a>
        <h1 style="text-align:center;">Project Details</h1>
    </header>

    <main class="main-content">
        <div class="project-card">
            <h2><?= htmlspecialchars($project['project_name']) ?></h2>
            <p><?= nl2br(htmlspecialchars($project['description'])) ?></p>
            
            <?php if(isset($project['status'])): ?>
                <p><strong>Status:</strong> <?= htmlspecialchars($project['status']) ?></p>
            <?php endif; ?>
            
            <p><strong>Created At:</strong> <?= htmlspecialchars($project['created_at']) ?> (<?= time_elapsed($project['created_at']) ?>)</p>

            <?php if(isset($project['completed_at']) && $project['completed_at'] !== null): ?>
                <p><strong>Completed At:</strong> <?= htmlspecialchars($project['completed_at']) ?> (<?= time_elapsed($project['completed_at']) ?>)</p>
            <?php endif; ?>

            <!-- Mark as Completed Button -->
            <?php if(!isset($project['status']) || $project['status'] !== 'Completed'): ?>
                <form method="POST">
                    <button type="submit" name="mark_completed" class="mark-completed-btn">Mark as Completed</button>
                </form>
            <?php else: ?>
                <p style="color: green; font-weight: bold;">This project is completed ✅</p>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
