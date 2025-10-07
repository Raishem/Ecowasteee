<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("SELECT sp.*, u.username FROM shared_projects sp LEFT JOIN users u ON u.user_id = sp.user_id ORDER BY sp.created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$res = $stmt->get_result();
$shared = $res->fetch_all(MYSQLI_ASSOC);

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Shared Feed | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/homepage.css">
    <link rel="stylesheet" href="assets/css/project-details.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .shared-card { background: #fff; border:1px solid #eee; padding:16px; border-radius:8px; margin-bottom:12px; }
        .shared-title { color:#2e8b57; font-weight:700; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <main>
            <h2>Community Shared Projects</h2>
            <?php if (empty($shared)): ?>
                <p>No shared projects yet.</p>
            <?php else: ?>
                <?php foreach ($shared as $s): ?>
                    <div class="shared-card">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <div class="shared-title"><?php echo htmlspecialchars($s['title']); ?></div>
                                <div style="font-size:13px;color:#666;">By <?php echo htmlspecialchars($s['username'] ?? 'Unknown'); ?> • <?php echo date('M d, Y', strtotime($s['created_at'])); ?></div>
                            </div>
                            <div>
                                <a href="shared_project.php?id=<?php echo $s['shared_id']; ?>" class="action-btn">View</a>
                            </div>
                        </div>
                        <p><?php echo nl2br(htmlspecialchars(substr($s['description'],0,300))); ?><?php if (strlen($s['description'])>300) echo '...'; ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
