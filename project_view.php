<?php
// recycled_idea_view.php
session_start();
require_once 'config.php';

$idea_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    $conn = getDBConnection();
    
    // Get idea details
    $stmt = $conn->prepare("SELECT * FROM recycled_ideas WHERE idea_id = ?");
    $stmt->bind_param("i", $idea_id);
    $stmt->execute();
    $idea = $stmt->get_result()->fetch_assoc();
    
    if (!$idea) {
        header('Location: homepage.php');
        exit();
    }
    
} catch (Exception $e) {
    header('Location: homepage.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($idea['title']) ?> | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <a href="homepage.php" class="back-link">&larr; Back to Recycled Ideas</a>
        
        <div class="idea-view">
            <h1><?= htmlspecialchars($idea['title']) ?></h1>
            
            <div class="idea-meta">
                <span class="author">By <?= htmlspecialchars($idea['author']) ?></span>
                <span class="date">Posted on <?= date('F j, Y', strtotime($idea['posted_at'])) ?></span>
            </div>
            
            <?php if (!empty($idea['image_path'])): ?>
                <div class="idea-main-image">
                    <img src="<?= htmlspecialchars($idea['image_path']) ?>" 
                         alt="<?= htmlspecialchars($idea['title']) ?>"
                         onerror="this.src='assets/img/default-project-image.jpg'">
                </div>
            <?php endif; ?>
            
            <div class="idea-description-full">
                <p><?= nl2br(htmlspecialchars($idea['description'])) ?></p>
            </div>
            
            <div class="idea-actions">
                <button class="btn primary" onclick="tryThisIdea('<?= addslashes($idea['title']) ?>')">
                    <i class="fas fa-plus"></i> Try This Idea
                </button>
            </div>
        </div>
    </div>
    
    <script>
        function tryThisIdea(ideaTitle) {
            <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
                if (confirm('Create a new project inspired by "' + ideaTitle + '"?')) {
                    window.location.href = 'create_project.php?inspiration=' + encodeURIComponent(ideaTitle);
                }
            <?php else: ?>
                if (confirm('Login to create a project?')) {
                    window.location.href = 'login.php?redirect=' + encodeURIComponent(window.location.href);
                }
            <?php endif; ?>
        }
    </script>
</body>
</html>