<?php
session_start();
require_once 'config.php';

$shared_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
if (!$shared_id) {
    echo "<p>Invalid shared project</p>";
    exit;
}

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT sp.*, u.username FROM shared_projects sp LEFT JOIN users u ON u.user_id = sp.user_id WHERE sp.shared_id = ?");
$stmt->execute([$shared_id]);
$proj = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$proj) {
    echo "<p>Shared project not found</p>";
    exit;
}

$mstmt = $conn->prepare("SELECT * FROM shared_materials WHERE shared_id = ?");
$mstmt->execute([$shared_id]);
$materials = $mstmt->fetchAll(PDO::FETCH_ASSOC);

$sstmt = $conn->prepare("SELECT * FROM shared_steps WHERE shared_id = ? ORDER BY step_number");
$sstmt->execute([$shared_id]);
$steps = $sstmt->fetchAll(PDO::FETCH_ASSOC);

?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($proj['title']); ?> - Shared</title>
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/project-details.css">
    <script>
    function postActivity(type, data, cb) {
        var fd = new FormData();
        fd.append('action', 'shared_activity');
        fd.append('shared_id', <?php echo $shared_id; ?>);
        fd.append('activity_type', type);
        for (var k in data) fd.append(k, data[k]);

        fetch('update_project.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(cb).catch(e => { /* silenced */ });
    }

    function toggleLike(el){
        postActivity('like', {}, function(res){
            if (res.success) {
                el.textContent = res.liked ? 'Unlike' : 'Like';
            } else {
                alert(res.message || 'Error');
            }
        });
    }

    // Silence console output on this page (non-invasive)
    (function(){
        try {
            if (typeof window !== 'undefined' && !window.__silentConsolePatchApplied) {
                ['log','debug','info','warn','error'].forEach(function(fn){
                    try { if (console && console[fn]) console[fn] = function(){}; } catch(e){}
                });
                window.__silentConsolePatchApplied = true;
            }
        } catch(e) { /* ignore */ }
    })();

    function submitComment(){
        var txt = document.getElementById('comment_text').value;
        postActivity('comment', { comment: txt }, function(res){
            if (res.success) {
                var list = document.getElementById('comments');
                var li = document.createElement('li');
                li.textContent = txt;
                list.insertBefore(li, list.firstChild);
                document.getElementById('comment_text').value = '';
            } else alert(res.message || 'Error');
        });
    }
    </script>
</head>
<body>
    <div class="container">
        <h1><?php echo htmlspecialchars($proj['title']); ?></h1>
        <p>By <?php echo htmlspecialchars($proj['username'] ?? 'Unknown'); ?> on <?php echo $proj['created_at']; ?></p>
        <?php if ($proj['cover_photo']): ?>
            <img src="uploads/<?php echo htmlspecialchars($proj['cover_photo']); ?>" alt="cover" style="max-width:100%;">
        <?php endif; ?>
        <div class="description"><?php echo nl2br(htmlspecialchars($proj['description'])); ?></div>

        <h3>Materials</h3>
        <ul>
        <?php foreach ($materials as $m): ?>
            <li><?php echo htmlspecialchars($m['name']); ?> - <?php echo htmlspecialchars($m['quantity']); ?></li>
        <?php endforeach; ?>
        </ul>

        <h3>Steps</h3>
        <ol>
        <?php foreach ($steps as $s): ?>
            <li><?php echo htmlspecialchars($s['title']); ?><?php if ($s['instructions']): ?><div><?php echo nl2br(htmlspecialchars($s['instructions'])); ?></div><?php endif; ?></li>
        <?php endforeach; ?>
        </ol>

        <div>
            <button onclick="toggleLike(this)" id="likeBtn">Like</button>
            <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $proj['user_id']): ?>
                <button id="unpublishBtn" style="margin-left:8px;background:#d9534f;color:#fff;">Unpublish</button>
            <?php endif; ?>
        </div>

        <div>
            <h4>Comments</h4>
            <input id="comment_text" placeholder="Write a comment">
            <button onclick="submitComment()">Post</button>
            <ul id="comments"></ul>
        </div>
    </div>
    <script>
    const unpublishBtn = document.getElementById('unpublishBtn');
    if (unpublishBtn) {
        unpublishBtn.addEventListener('click', function(){
            if (!confirm('Unpublish this shared project? This action cannot be undone.')) return;
            const fd = new FormData();
            fd.append('action', 'unpublish_shared_project');
            fd.append('shared_id', <?php echo $shared_id; ?>);
            fetch('update_project.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                if (res.success) {
                    alert('Unpublished');
                    window.location.href = 'shared_feed.php';
                } else {
                    alert(res.message || 'Failed to unpublish');
                }
            }).catch(err => { alert('Error'); });
        });
    }
    </script>
</body>
</html>
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