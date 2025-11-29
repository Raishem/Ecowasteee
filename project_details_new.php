<?php
session_start();
require_once 'config.php';

// Initialize variables
$project = [];
$materials = [];
$steps = [];
$step_progress = [];
$success_message = '';
$error_message = '';
$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check login status
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

try {
    $conn = getDBConnection();
    
    // Get project details
    $stmt = $conn->prepare("SELECT * FROM projects WHERE project_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $project_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();

    if (!$project) {
        header('Location: projects.php');
        exit();
    }

    // Get project materials
    $materials_stmt = $conn->prepare("SELECT * FROM project_materials WHERE project_id = ?");
    $materials_stmt->bind_param("i", $project_id);
    $materials_stmt->execute();
    $materials = $materials_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get project steps
    $steps_stmt = $conn->prepare("SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_number");
    $steps_stmt->bind_param("i", $project_id);
    $steps_stmt->execute();
    $steps = $steps_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get step progress if tracking is enabled
    try {
        $prog_stmt = $conn->prepare("SELECT step_id, is_done FROM project_step_progress WHERE project_id = ?");
        if ($prog_stmt) {
            $prog_stmt->bind_param("i", $project_id);
            $prog_stmt->execute();
            $prog_res = $prog_stmt->get_result();
            while ($pr = $prog_res->fetch_assoc()) {
                $step_progress[(int)$pr['step_id']] = (bool)$pr['is_done'];
            }
        }
    } catch (mysqli_sql_exception $e) {
        // Table might not exist - leave $step_progress empty
    }
    
} catch (mysqli_sql_exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Get user data for header
try {
    $user_stmt = $conn->prepare("SELECT username, avatar FROM users WHERE user_id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
} catch (mysqli_sql_exception $e) {
    $user_data = ['username' => 'User', 'avatar' => ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/project-details-new.css">
    <!-- Shared stages styling so workflow tabs match other project pages -->
    <link rel="stylesheet" href="assets/css/project-stages.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Header -->
    <header>
        <div class="logo-container">
            <div class="logo">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo">
                <h1>EcoWaste</h1>
            </div>
        </div>
        <div class="header-actions">
            <div class="notifications-icon">
                <i class="fas fa-bell"></i>
                <span class="notification-badge">0</span>
            </div>
            <div class="user-profile" id="userProfile">
                <div class="profile-pic">
                    <?php if (!empty($user_data['avatar'])): ?>
                        <img src="<?= htmlspecialchars($user_data['avatar']) ?>" alt="User Avatar">
                    <?php else: ?>
                        <?= strtoupper(substr($user_data['username'] ?? 'U', 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <span class="profile-name"><?= htmlspecialchars($user_data['username'] ?? 'User') ?></span>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                <div class="profile-dropdown">
                    <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                    <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Left Sidebar -->
        <aside class="sidebar">
            <nav>
                <a href="homepage.php">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="browse.php">
                    <i class="fas fa-search"></i> Browse
                </a>
                <a href="projects.php" class="active">
                    <i class="fas fa-recycle"></i> My Projects
                </a>
                <a href="donations.php">
                    <i class="fas fa-hand-holding-heart"></i> Donations
                </a>
                <a href="achievements.php">
                    <i class="fas fa-trophy"></i> Achievements
                </a>
                <a href="leaderboard.php">
                    <i class="fas fa-crown"></i> Leaderboard
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="project-details">
            <div class="back-button">
                <a href="projects.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Projects
                </a>
            </div>

            <?php if ($success_message): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <div class="project-header">
                <h1 class="project-title"><?= htmlspecialchars($project['project_name']) ?></h1>
                <div class="project-description">
                    <?= nl2br(htmlspecialchars($project['description'])) ?>
                </div>
                
                <div class="action-buttons">
                    <button class="edit-btn" data-action="edit-project">
                        <i class="fas fa-edit"></i> Edit Project
                    </button>
                    <?php if (isset($project['status']) && $project['status'] === 'completed'): ?>
                        <button id="shareBtn" class="share-btn">
                            <i class="fas fa-share"></i> Share Project
                        </button>
                    <?php endif; ?>
                </div>
                
                <div class="project-meta">
                    <i class="far fa-calendar-alt"></i>
                    <span>Created: <?= date('M d, Y', strtotime($project['created_at'])) ?></span>
                </div>
            </div>

            <!-- Workflow & Materials Section (ensure consistent 3 tabs across projects) -->
            <section class="workflow-section card">
                <h2 class="section-title"><i class="fas fa-tasks"></i> Project Workflow</h2>
                <div class="progress-indicator"><strong>0%</strong> of stages completed. (0 of 3)</div>
                <div class="progress-bar"><div class="progress-fill" style="width:0%"></div></div>

                <?php
                // Server-driven workflow: force three stages (Preparation, Construction, Share)
                $desired = [
                    ['key' => 'preparation', 'label' => 'Preparation', 'description' => 'Collect materials required for this project'],
                    ['key' => 'construction', 'label' => 'Construction', 'description' => 'Build your project'],
                    ['key' => 'share', 'label' => 'Share', 'description' => 'Share your project with the community']
                ];

                $available_templates = [];
                try {
                    $tpl_stmt = $conn->prepare("SELECT stage_number, stage_name, description FROM stage_templates");
                    if ($tpl_stmt) { $tpl_stmt->execute(); $tres = $tpl_stmt->get_result(); while ($r = $tres->fetch_assoc()) { $available_templates[] = $r; } }
                } catch (Exception $e) { /* ignore */ }

                $workflow_stages = [];
                foreach ($desired as $i => $d) {
                    $foundTemplate = null;
                    foreach ($available_templates as $t) {
                        $name = strtolower($t['stage_name'] ?? '');
                        if ($d['key'] === 'preparation' && (stripos($name, 'prepar') !== false || stripos($name, 'material') !== false)) { $foundTemplate = $t; break; }
                        if ($d['key'] === 'construction' && stripos($name, 'construct') !== false) { $foundTemplate = $t; break; }
                        if ($d['key'] === 'share' && stripos($name, 'share') !== false) { $foundTemplate = $t; break; }
                    }
                    $tplNum = null; $desc = $d['description'];
                    if ($foundTemplate) { $tplNum = (int)$foundTemplate['stage_number']; if (!empty($foundTemplate['description'])) $desc = $foundTemplate['description']; }
                    $workflow_stages[] = ['name' => $d['label'], 'description' => $desc, 'number' => $i + 1, 'template_number' => $tplNum];
                }

                // Map template_number => index
                $numToIndex = [];
                foreach ($workflow_stages as $i => $st) { $num = isset($st['template_number']) ? (int)$st['template_number'] : (int)($st['number'] ?? ($i+1)); $numToIndex[$num] = $i; }

                // Get completed stages for this project
                $completed_stage_map = [];
                try {
                    $stage_stmt = $conn->prepare("SELECT stage_number, MAX(completed_at) AS completed_at FROM project_stages WHERE project_id = ? GROUP BY stage_number");
                    if ($stage_stmt) { $stage_stmt->bind_param('i', $project_id); $stage_stmt->execute(); $stage_result = $stage_stmt->get_result(); while ($s = $stage_result->fetch_assoc()) { $raw_num = (int)$s['stage_number']; if (!is_null($s['completed_at']) && isset($numToIndex[$raw_num])) { $idx = $numToIndex[$raw_num]; $completed_stage_map[$idx] = $s['completed_at']; } } }
                } catch (Exception $e) { $completed_stage_map = []; }

                $total_stages = count($workflow_stages);
                $completed_stages = 0;
                for ($i = 0; $i < $total_stages; $i++) { if (array_key_exists($i, $completed_stage_map)) $completed_stages++; }
                $completed_stages = max(0, min($completed_stages, $total_stages));
                $progress_percent = $total_stages > 0 ? (int) round(($completed_stages / $total_stages) * 100) : 0;
                if ($total_stages === 0) $current_stage_index = 0; elseif ($completed_stages >= $total_stages) $current_stage_index = max(0, $total_stages - 1); else $current_stage_index = $completed_stages;
                ?>

                <div class="stage-tabs">
                    <?php foreach ($workflow_stages as $i => $st):
                        $tn = isset($st['template_number']) ? (int)$st['template_number'] : (int)($st['number'] ?? $i + 1);
                        $is_completed = array_key_exists($i, $completed_stage_map);
                        $is_current = !$is_completed && ($i === $current_stage_index);
                        $is_locked = !$is_completed && ($i > $current_stage_index);
                        $badgeClass = $is_completed ? 'completed' : ($is_current ? 'current' : ($is_locked ? 'locked' : 'incomplete'));
                        $stage_name_lower = strtolower($st['name'] ?? '');
                        $iconClass = 'fas fa-circle'; if (stripos($stage_name_lower, 'material') !== false) $iconClass = 'fas fa-box-open'; elseif (stripos($stage_name_lower, 'prepar') !== false) $iconClass = 'fas fa-tools'; elseif (stripos($stage_name_lower, 'construct') !== false) $iconClass = 'fas fa-hard-hat'; elseif (stripos($stage_name_lower, 'share') !== false) $iconClass = 'fas fa-share-alt';
                    ?>
                    <button class="stage-tab <?= ($i === $current_stage_index) ? 'active' : '' ?> <?= $is_locked ? 'locked' : '' ?>" data-stage-index="<?= $i ?>" data-stage-number="<?= $tn ?>">
                        <span class="tab-icon"><i class="<?= $iconClass ?>"></i></span>
                        <span class="tab-meta"><span class="tab-title"><?= htmlspecialchars($st['name']) ?></span><span class="tab-badge <?= $badgeClass ?>"><?php echo $is_completed ? 'Completed' : ($is_current ? 'Current' : ($is_locked ? 'Locked' : 'Incomplete')) ?></span></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <div class="workflow-stages-container stages-timeline">
                    <?php foreach ($workflow_stages as $index => $stage):
                        $is_completed = array_key_exists($index, $completed_stage_map);
                        $is_current = !$is_completed && ($index === $current_stage_index);
                        $is_locked = !$is_completed && ($index > $current_stage_index);
                        $stage_class = $is_completed ? 'completed' : ($is_current ? 'current' : ($is_locked ? 'locked' : 'inactive'));
                    ?>
                    <div class="workflow-stage stage-card <?= $stage_class ?> <?= $is_current ? 'active' : '' ?>" data-stage-index="<?= $index ?>">
                        <i class="fas fa-circle stage-icon" aria-hidden="true"></i>
                        <div class="stage-content">
                            <div class="stage-header">
                                <div class="stage-info">
                                    <h3 class="stage-title"><?= htmlspecialchars($stage['name']) ?> <?php if ($is_completed): ?><i class="fas fa-check-circle stage-check" title="Completed"></i><?php endif; ?></h3>
                                    <div class="stage-desc"><?= nl2br(htmlspecialchars($stage['description'] ?? '')) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Materials Section -->
            <div class="materials-section">
                    <div class="materials-header">
                    <div class="materials-title-wrapper">
                        <i class="fas fa-box"></i>
                        <h2>Materials Needed</h2>
                    </div>
                </div>

                <div class="materials-list">
                    <ul>
                        <?php if (empty($materials)): ?>
                            <li class="empty-state">No materials listed.</li>
                        <?php else: ?>
                            <?php foreach ($materials as $material): ?>
                                <li class="material-item">
                                    <span class="material-name"><?= htmlspecialchars($material['name'] ?? $material['material_name'] ?? '') ?></span>
                                    <?php if (!empty($material['quantity'])): ?>
                                        <span class="material-quantity">&nbsp;—&nbsp;<?= htmlspecialchars($material['quantity']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($material['status'])): ?>
                                        <span class="material-status-label">&nbsp;(<span class="status-text"><?= ucfirst($material['status']) ?></span>)</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // User Profile Dropdown
        const userProfile = document.getElementById('userProfile');
        if (userProfile) {
            userProfile.addEventListener('click', function() {
                this.querySelector('.profile-dropdown').classList.toggle('show');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!userProfile.contains(e.target)) {
                    userProfile.querySelector('.profile-dropdown').classList.remove('show');
                }
            });
        }
    });
    </script>
</body>
</html>