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

// Fetch all projects
$stmt = $conn->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$all_projects = [];
while ($row = $result->fetch_assoc()) {
    $all_projects[] = $row;
}

// Separate projects by status
$in_progress = [];
$completed = [];
foreach ($all_projects as $p) {
    if (isset($p['status'])) {
        if ($p['status'] === 'In Progress') $in_progress[] = $p;
        if ($p['status'] === 'Completed') $completed[] = $p;
    }
}


// Get user info
$stmt_user = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$user_result = $stmt_user->get_result();
$user = $user_result->fetch_assoc();
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Helper function for elapsed time
function time_elapsed($datetime) {
    $now = new DateTime();
    $created = new DateTime($datetime);
    $diff = $now->diff($created);

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
    <title>Projects | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/projects.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-pic { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px; overflow: hidden; background-color: #3d6a06ff; color: white; font-weight: bold; font-size: 18px; }
        .filter-tab { cursor: pointer; margin-right: 10px; font-weight: bold; }
        .filter-tab.active { color: #2e8b57; }
        .empty-state { text-align: center; margin-top: 30px; font-style: italic; color: #555; }
        .project-card { border: 1px solid #ccc; border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #f7f7f7; }
        .project-card p { margin: 5px 0; }
        .action-btn { display: inline-block; margin-top: 10px; padding: 8px 15px; background: #2e8b57; color: white; border-radius: 5px; text-decoration: none; }
        .action-btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
<header>
    <div class="logo-container">
        <div class="logo"><img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo"></div>
        <h1>EcoWaste</h1>
    </div>
    <div class="user-profile" id="userProfile">
        <div class="profile-pic"><?= strtoupper(substr(htmlspecialchars($_SESSION['first_name'] ?? 'User'), 0, 1)) ?></div>
        <span class="profile-name"><?= htmlspecialchars($_SESSION['first_name'] ?? 'User') ?></span>
        <i class="fas fa-chevron-down dropdown-arrow"></i>
        <div class="profile-dropdown">
            <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
            <a href="#" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
            <div class="dropdown-divider"></div>
            <a href="logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <aside class="sidebar">
        <nav>
            <ul>
                <li><a href="homepage.php"><i class="fas fa-home"></i>Home</a></li>
                <li><a href="browse.php"><i class="fas fa-search"></i>Browse</a></li>
                <li><a href="achievements.php"><i class="fas fa-star"></i>Achievements</a></li>
                <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                <li><a href="projects.php" style="color: #2e8b57;"><i class="fas fa-recycle"></i>Projects</a></li>
                <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-header">
            <h2 class="page-title">My Recycling Projects</h2>
            <a href="start_project.php" class="start-recycling-btn">Start Recycling</a>
        </div>

        <!-- Tabs -->
        <div style="margin-bottom:20px;">
            <span class="filter-tab active" data-filter="all">All</span>
            <span class="filter-tab" data-filter="in-progress">In Progress</span>
            <span class="filter-tab" data-filter="completed">Completed</span>
        </div>

        <!-- All Projects -->
        <div class="projects-container" id="all">
            <?php if(empty($all_projects)): ?>
                <div class="empty-state"><p>No projects yet. Start one!</p></div>
            <?php else: ?>
                <?php foreach($all_projects as $project): ?>
                    <div class="project-card">
                        <h3><?= htmlspecialchars($project['project_name']) ?></h3>
                        <p><?= htmlspecialchars($project['description']) ?></p>
                        <?php if(isset($project['status'])): ?>
                            <p><small>Status: <?= htmlspecialchars($project['status']) ?></small></p>
                        <?php endif; ?>
                        <?php if(isset($project['completed_at']) && $project['completed_at'] !== null): ?>
                            <p><small>Completed: <?= isset($project['completed_at']) && $project['completed_at'] !== null ? htmlspecialchars($project['completed_at']) : 'Not completed yet' ?></small></p>

                        <?php endif; ?>
                        <a href="project_detail.php?id=<?= $project['project_id'] ?>" class="action-btn">View Details</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- In Progress -->
        <div class="projects-container" id="in-progress" style="display:none;">
            <?php if(empty($in_progress)): ?>
                <div class="empty-state"><p>No in-progress projects.</p></div>
            <?php else: ?>
                <?php foreach($in_progress as $project): ?>
                    <div class="project-card">
                        <h3><?= htmlspecialchars($project['project_name']) ?></h3>
                        <p><?= htmlspecialchars($project['description']) ?></p>
                        <p><small>Started: <?= time_elapsed($project['created_at']) ?></small></p>
                        <a href="project_detail.php?id=<?= $project['project_id'] ?>" class="action-btn">View Details</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Completed -->
        <div class="projects-container" id="completed" style="display:none;">
            <?php if(empty($completed)): ?>
                <div class="empty-state"><p>No completed projects yet.</p></div>
            <?php else: ?>
                <?php foreach($completed as $project): ?>
                    <div class="project-card">
                        <h3><?= htmlspecialchars($project['project_name']) ?></h3>
                        <p><?= htmlspecialchars($project['description']) ?></p>
                        <?php if(isset($project['completed_at']) && $project['completed_at'] !== null): ?>
                            <p><small>Completed At: <?= htmlspecialchars($project['completed_at']) ?></small></p>
                        <?php endif; ?>
                        <a href="project_detail.php?id=<?= $project['project_id'] ?>" class="action-btn">View Details</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    // Tabs
    document.querySelectorAll(".filter-tab").forEach(tab => {
        tab.addEventListener("click", () => {
            document.querySelectorAll(".filter-tab").forEach(t => t.classList.remove("active"));
            tab.classList.add("active");
            document.querySelectorAll(".projects-container").forEach(c => c.style.display = "none");
            document.getElementById(tab.dataset.filter).style.display = "block";
        });
    });

    // User dropdown
    document.getElementById('userProfile').addEventListener('click', function() {
        this.classList.toggle('active');
    });
    document.addEventListener('click', function(event) {
        const userProfile = document.getElementById('userProfile');
        if (!userProfile.contains(event.target)) userProfile.classList.remove('active');
    });
</script>
</body>
</html>
