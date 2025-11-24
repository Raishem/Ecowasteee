<?php
session_start();
require_once 'config.php';
$conn = getDBConnection();

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Fetch user info
$stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

// Update session if missing
if (!isset($_SESSION['first_name']) && isset($user_data['first_name'])) {
    $_SESSION['first_name'] = $user_data['first_name'];
    $_SESSION['last_name'] = $user_data['last_name'];
    $_SESSION['user_email'] = $user_data['email'];
}

// ================= Legacy Accounts Fix =================
// Sum all claimed points from user_tasks for this user
$claimed_points_row = $conn->query("
    SELECT IFNULL(SUM(reward_value),0) AS total_claimed
    FROM user_tasks
    WHERE user_id = $user_id AND reward_claimed = 1
")->fetch_assoc();

$total_claimed_points = (int)($claimed_points_row['total_claimed'] ?? 0);

// Ensure user_stats row exists for this user
$stats_row = $conn->query("SELECT * FROM user_stats WHERE user_id = $user_id")->fetch_assoc();
if (!$stats_row) {
    $conn->query("INSERT INTO user_stats (user_id, projects_completed, achievements_earned, badges_earned, items_recycled, total_points) 
                  VALUES ($user_id, 0, 0, 0, 0, $total_claimed_points)");
    $stats_row = [
        'projects_completed' => 0,
        'achievements_earned' => 0,
        'badges_earned' => 0,
        'items_recycled' => 0,
        'total_points' => $total_claimed_points
    ];
} else {
    // Update total_points for legacy accounts
    $conn->query("UPDATE user_stats SET total_points = $total_claimed_points WHERE user_id = $user_id");
}
// ========================================================


// ✅ Use total_points from user_stats
$total_points = (int)($stats_row['total_points'] ?? 0);


// ✅ Calculate live achievements earned from claimed tasks
$achievements_row = $conn->query("
    SELECT COUNT(*) AS claimed_tasks 
    FROM user_tasks 
    WHERE user_id = $user_id AND reward_claimed = 1
")->fetch_assoc();
$achievements_earned = (int)($achievements_row['claimed_tasks'] ?? 0);

// Build stats array using live achievements count
$stats = [
    'projects_completed'   => (int) ($stats_row['projects_completed'] ?? 0),
    'achievements_earned'  => $achievements_earned,   // ✅ FIXED
    'badges_earned'        => (int) ($stats_row['badges_earned'] ?? 0),
    'items_recycled'       => (int) ($stats_row['items_recycled'] ?? 0)
];



// Count total donations (number of times user donated)
$donations_result = $conn->query("SELECT COUNT(*) AS total_donations FROM donations WHERE donor_id = $user_id");
$donations_row = $donations_result->fetch_assoc();
$total_donations = $donations_row['total_donations'] ?? 0;


// ✅ Function: points needed per level
function getPointsForLevel($level) {
    $points = 25 + ($level * 5); // 25 → 30 → 35 … capped at 100
    return min($points, 100);
}

// ✅ Calculate current level & progress
$level = 0;
$remaining_points = $total_points;
while ($level < 50) { // Max level = 50
    $required = getPointsForLevel($level);
    if ($remaining_points >= $required) {
        $remaining_points -= $required;
        $level++;
    } else break;
}

$display_level = $level + 1;


// Points toward next level
$current_level_points = $remaining_points;
$progress_percentage = ($current_level_points / getPointsForLevel($level)) * 100;


// Fetch tasks
$tasks = [];
$result = $conn->query("SELECT * FROM user_tasks WHERE user_id = $user_id ORDER BY task_id ASC");

while ($row = $result->fetch_assoc()) $tasks[] = $row;
// If no tasks exist, create default tasks for the user
if (empty($tasks)) {
    $default_tasks = [
        // Donation-related tasks
        [
            'title' => 'Rising Donor',
            'description' => 'Complete your first donation',
            'reward' => '20 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 20,
            'progress' => '0/1',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 1,
            'action_type' => 'donations'
        ],
        [
            'title' => 'Helpful Friend',
            'description' => 'Complete 10 donations',
            'reward' => '50 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 50,
            'progress' => '0/10',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 10,
            'action_type' => 'donations'
        ],
        [
            'title' => 'Care Giver',
            'description' => 'Complete 15 donations',
            'reward' => '100 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 100,
            'progress' => '0/15',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 15,
            'action_type' => 'donations'
        ],
        [
            'title' => 'Generous Giver',
            'description' => 'Complete 20 donations',
            'reward' => '150 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 150,
            'progress' => '0/20',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 20,
            'action_type' => 'donations'
        ],
        [
            'title' => 'Community Helper',
            'description' => 'Complete 25 donations',
            'reward' => '200 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 200,
            'progress' => '0/25',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 25,
            'action_type' => 'donations'
        ],
        [
            'title' => 'Charity Champion',
            'description' => 'Complete 30 donations',
            'reward' => '250 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 250,
            'progress' => '0/30',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 30,
            'action_type' => 'donations'
        ],
        
        // Project creation tasks
        [
            'title' => 'Eco Beginner',
            'description' => 'Start your first recycling project',
            'reward' => '20 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 20,
            'progress' => '0/1',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 1,
            'action_type' => 'projects_created'
        ],
        [
            'title' => 'Eco Builder',
            'description' => 'Create 10 recycling projects',
            'reward' => '50 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 50,
            'progress' => '0/10',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 10,
            'action_type' => 'projects_created'
        ],
        [
            'title' => 'Nature Keeper',
            'description' => 'Create 15 recycling projects',
            'reward' => '100 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 100,
            'progress' => '0/15',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 15,
            'action_type' => 'projects_created'
        ],
        [
            'title' => 'Conservation Expert',
            'description' => 'Create 20 recycling projects',
            'reward' => '150 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 150,
            'progress' => '0/20',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 20,
            'action_type' => 'projects_created'
        ],
        [
            'title' => 'Zero Waste Hero',
            'description' => 'Create 25 recycling projects',
            'reward' => '200 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 200,
            'progress' => '0/25',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 25,
            'action_type' => 'projects_created'
        ],
        [
            'title' => 'Earth Saver',
            'description' => 'Create 30 recycling projects',
            'reward' => '250 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 250,
            'progress' => '0/30',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 30,
            'action_type' => 'projects_created'
        ],
        
        // Recycling project completion tasks
        [
            'title' => 'Eco Star',
            'description' => 'Complete a recycling project',
            'reward' => '20 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 20,
            'progress' => '0/1',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 1,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Warrior',
            'description' => 'Complete 10 recycling projects',
            'reward' => '50 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 50,
            'progress' => '0/10',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 10,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Elite',
            'description' => 'Complete 15 recycling projects',
            'reward' => '100 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 100,
            'progress' => '0/15',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 15,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Pro',
            'description' => 'Complete 20 recycling projects',
            'reward' => '150 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 150,
            'progress' => '0/20',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 20,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Master',
            'description' => 'Complete 25 recycling projects',
            'reward' => '200 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 200,
            'progress' => '0/25',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 25,
            'action_type' => 'projects_completed'
        ],
        [
            'title' => 'Eco Legend',
            'description' => 'Complete 30 recycling projects',
            'reward' => '250 EcoPoints',
            'reward_type' => 'points',
            'reward_value' => 250,
            'progress' => '0/30',
            'status' => 'In Progress',
            'current' => 0,
            'target' => 30,
            'action_type' => 'projects_completed'
        ]
    ];
    
foreach ($default_tasks as $task) {
        $title = $conn->real_escape_string($task['title']);
        $description = $conn->real_escape_string($task['description']);
        $reward = $conn->real_escape_string($task['reward']);
        $reward_type = $conn->real_escape_string($task['reward_type']);
        $reward_value = $task['reward_value'];
        $progress = $conn->real_escape_string($task['progress']);
        $status = $conn->real_escape_string($task['status']);
        $current = $task['current'];
        $target = $task['target'];
        $action_type = $conn->real_escape_string($task['action_type']);

        $columns = "user_id, title, description, reward, progress, status, current_value, target_value, reward_type, reward_value, action_type, unlocked";
        // Unlock only first task per category
        $unlocked = 0;
        if (
            ($action_type === 'donations' && $target == 1) ||
            ($action_type === 'projects_created' && $target == 1) ||
            ($action_type === 'projects_completed' && $target == 1)
        ) {
            $unlocked = 1;
        }

        $values = "$user_id, '$title', '$description', '$reward', '$progress', '$status', $current, $target, '$reward_type', $reward_value, '$action_type', $unlocked";
        $conn->query("INSERT INTO user_tasks ($columns) VALUES ($values)");
    }

    // reload tasks
    $result = $conn->query("SELECT * FROM user_tasks WHERE user_id = $user_id ORDER BY task_id ASC");
    while ($row = $result->fetch_assoc()) $tasks[] = $row;
}

// ✅ Redeem reward
if (isset($_POST['redeem_task_id'])) {
    $task_id = (int)$_POST['redeem_task_id'];
    $task = $conn->query("SELECT * FROM user_tasks WHERE task_id=$task_id AND user_id=$user_id")->fetch_assoc();

    if ($task && $task['status'] == 'Completed' && !$task['reward_claimed']) {
        $reward_value = (int)$task['reward_value'];

        // SAVE POINTS TO THE CORRECT TABLE
        if ($task['reward_type'] == 'points') {
            $conn->query("UPDATE user_stats SET total_points = total_points + $reward_value WHERE user_id = $user_id");
        }

        // Mark reward claimed
        $conn->query("UPDATE user_tasks SET reward_claimed=1 WHERE task_id=$task_id");

        // Increment achievements earned
        $conn->query("UPDATE user_stats SET achievements_earned = achievements_earned + 1 WHERE user_id = $user_id");
        $stats['achievements_earned'] = ($stats['achievements_earned'] ?? 0) + 1;

        // Unlock next task
        $action_type = $conn->real_escape_string($task['action_type']);
        $next_task = $conn->query("SELECT * FROM user_tasks 
                                   WHERE user_id=$user_id AND action_type='$action_type' 
                                   AND unlocked=0 ORDER BY target_value ASC LIMIT 1")->fetch_assoc();
        if ($next_task) {
            $conn->query("UPDATE user_tasks SET unlocked=1 WHERE task_id={$next_task['task_id']}");
        }

        // Recalculate level (based on total_points)
        $user_points_row = $conn->query("SELECT total_points FROM user_stats WHERE user_id = $user_id")->fetch_assoc();
        $total_points = $user_points_row['total_points'] ?? 0;

        $level = 0;
        $remaining_points = $total_points;
        while ($level < 50) {
            $required = getPointsForLevel($level);
            if ($remaining_points >= $required) {
                $remaining_points -= $required;
                $level++;
            } else break;
        }

        $current_level_points = $remaining_points;
        $progress_percentage = ($current_level_points / getPointsForLevel($level)) * 100;
    }

    header("Location: achievements.php");
    exit;
}




function updateTaskProgress($conn, $user_id) {
    // 1️⃣ Load or initialize user_stats
    $stats_row = $conn->query("SELECT * FROM user_stats WHERE user_id = $user_id")->fetch_assoc();
    if (!$stats_row) {
        $conn->query("INSERT INTO user_stats (user_id, projects_completed, achievements_earned, badges_earned, items_recycled) 
                      VALUES ($user_id, 0, 0, 0, 0)");
        $stats_row = [
            'projects_completed' => 0,
            'badges_earned' => 0,
            'items_recycled' => 0
        ];
    }

    // 2️⃣ Calculate achievements earned (claimed tasks)
    $achievements_row = $conn->query("
        SELECT COUNT(*) AS claimed_tasks 
        FROM user_tasks 
        WHERE user_id = $user_id AND reward_claimed = 1
    ")->fetch_assoc();
    $achievements_earned = (int)($achievements_row['claimed_tasks'] ?? 0);

    // 3️⃣ Get other reliable totals
    $donations_row = $conn->query("SELECT COUNT(*) AS total_donations FROM donations WHERE donor_id = $user_id")->fetch_assoc();
    $total_donations = (int)($donations_row['total_donations'] ?? 0);

    $projects_created_row = $conn->query("SELECT COUNT(*) AS total_projects_created FROM projects WHERE user_id = $user_id")->fetch_assoc();
    $total_projects_created = (int)($projects_created_row['total_projects_created'] ?? 0);

    $total_projects_completed = (int)($stats_row['projects_completed'] ?? 0);
    $badges_earned = (int)($stats_row['badges_earned'] ?? 0);
    $items_recycled = (int)($stats_row['items_recycled'] ?? 0);

    // 4️⃣ Fetch and update user tasks progress
    $result = $conn->query("SELECT * FROM user_tasks WHERE user_id = $user_id ORDER BY action_type, task_id ASC");
    $prev_task_values = ['donations' => 0, 'projects_created' => 0, 'projects_completed' => 0];

    while ($task = $result->fetch_assoc()) {
        if ($task['unlocked'] == 0) continue; // skip locked tasks

        switch ($task['action_type']) {
            case 'donations': 
                $total_value = $total_donations; 
                break;
            case 'projects_created': 
                $total_value = $total_projects_created; 
                break;
            case 'projects_completed': 
                $total_value = $total_projects_completed; 
                break;
            default: 
                $total_value = 0;
        }

        $current_value = $total_value - $prev_task_values[$task['action_type']];
        $current_value = max(0, min($current_value, $task['target_value']));

        $status = ($current_value >= $task['target_value']) ? 'Completed' : 'In Progress';
        $progress = "$current_value/{$task['target_value']}";

        $conn->query("
            UPDATE user_tasks 
            SET current_value=$current_value, progress='$progress', status='$status' 
            WHERE task_id={$task['task_id']} AND user_id=$user_id
        ");

        if ($task['reward_claimed'] == 1) {
            $prev_task_values[$task['action_type']] += $task['target_value'];
        }
    }

    // 5️⃣ Recalculate total points and level
    $stats_row = $conn->query("SELECT * FROM user_stats WHERE user_id = $user_id")->fetch_assoc();
    $total_points = (int)($stats_row['total_points'] ?? 0);

    $level = 0;
    $remaining_points = $total_points;
    while ($level < 50) {
        $required = getPointsForLevel($level);
        if ($remaining_points >= $required) {
            $remaining_points -= $required;
            $level++;
        } else break;
    }

    $progress_percentage = ($remaining_points / getPointsForLevel($level)) * 100;

    // 6️⃣ Update session variables
    $_SESSION['achievements_earned'] = $achievements_earned;
    $_SESSION['total_points'] = $total_points;
    $_SESSION['level'] = $level;
    $_SESSION['progress_points'] = $remaining_points;
    $_SESSION['progress_percentage'] = $progress_percentage;

    // 7️⃣ Return updated tasks
    return $conn->query("SELECT * FROM user_tasks WHERE user_id = $user_id ORDER BY task_id ASC");
}




// refresh tasks
$result = updateTaskProgress($conn, $user_id);
$tasks = [];
while ($row = $result->fetch_assoc()) $tasks[] = $row;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achievements | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/achievement.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<style>
.success-message {
    background-color: #d4edda;
    color: #155724;
    padding: 12px 20px;
    border-radius: 5px;
    margin-bottom: 20px;
    border-left: 4px solid #28a745;
}

.redeem-form {
    margin-top: 15px;
}

.reward-claimed {
    margin-top: 15px;
    color: #28a745;
    font-weight: bold;
    display: flex;
    align-items: center;
    gap: 8px;
}
</style>

<body>
    <header>
        <div class="logo-container">
            <div class="logo">
                <img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo">
            </div>
            <h1>EcoWaste</h1>
        </div>
<div class="user-profile" id="userProfile">
    <div class="profile-pic">
        <?= strtoupper(substr(htmlspecialchars($_SESSION['first_name'] ?? 'User'), 0, 1)) ?>
    </div>
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
                    <li><a href="achievements.php" class="active"><i class="fas fa-star"></i>Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-hand-holding-heart"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>

 <main class="main-content">
    <div class="achievements-header">
        <h2>My Achievements</h2>
        <p class="subtitle">Track your eco-friendly progress and accomplishments</p>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="success-message"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
    <?php endif; ?>

    <div class="achievements-content">
        <!-- Level Card -->
        <div class="level-card">
            <div class="circular-progress">
                <svg class="progress-ring" width="200" height="200">
                    <circle class="progress-ring-circle" stroke="#e0e0e0" stroke-width="10" fill="transparent" r="90" cx="100" cy="100"/>
                    <circle class="progress-ring-progress" stroke="#82AA52" stroke-width="10" fill="transparent" r="90" cx="100" cy="100"
                        stroke-dasharray="565.48" stroke-dashoffset="<?= 565.48 - (565.48 * ($_SESSION['progress_percentage'] ?? $progress_percentage) / 100) ?>"/>
                </svg>
                <div class="circle">
                    <div class="circle-inner">
                        <div class="level-number"><?= ($_SESSION['level'] ?? $level) + 1 ?></div>
                        <div class="level-label">LEVEL</div>
                    </div>
                </div>
            </div>
            <div class="progress-text"><?= $_SESSION['progress_points'] ?? $current_level_points ?>/<?= getPointsForLevel($_SESSION['level'] ?? $level) ?> pts
            </div>

            <div class="current-level">Progress to Level <?= $display_level + 1 ?></div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number"><?= (int) ($stats['projects_completed'] ?? 0) ?></div>
                <div class="stat-label">Projects Completed</div></div>
            <div class="stat-item">
                <div class="stat-number"><?= (int) ($stats['achievements_earned'] ?? 0) ?></div>
                <div class="stat-label">Achievements Earned</div></div>
            <div class="stat-item">
                <div class="stat-number"><?= (int) ($stats['badges_earned'] ?? 0) ?></div>
                <div class="stat-label">Badges Earned</div></div>
            <div class="stat-item">
                <div class="stat-number"><?= htmlspecialchars($total_donations) ?></div>
                <div class="stat-label">Total Donations</div></div>

            <div class="stat-item">
                <div class="stat-number"><?= (int) ($stats['items_recycled'] ?? 0) ?></div>
                <div class="stat-label">Total Items Recycled</div></div>
            <div class="stat-item">
                <div class="stat-number"><?= htmlspecialchars($total_points) ?></div>
            <div class="stat-label">Total Points</div></div>
        </div>

 <!-- Tasks -->
<div class="tasks-section">
    <div class="tasks-header"><h3>My Tasks</h3></div>
    <p class="tasks-subtitle">Complete tasks by category to earn more badges and points!</p>

    <?php
    // Group tasks by action_type
    $grouped_tasks = [
        'donations' => [],
        'projects_created' => [],
        'projects_completed' => []
    ];

    foreach ($tasks as $task) {
        if (!empty($task['action_type']) && isset($grouped_tasks[$task['action_type']])) {
            $grouped_tasks[$task['action_type']][] = $task;
        }
    }

    // Labels for categories
    $category_labels = [
        'donations' => 'Donation-related Tasks',
        'projects_created' => 'Project Creation Tasks',
        'projects_completed' => 'Recycling Project Completion Tasks'
    ];
    ?>

    <div class="task-categories">
        <?php foreach ($grouped_tasks as $type => $task_list): ?>
            <div class="task-category">
                <div class="category-header">
                    <h4><?= $category_labels[$type] ?? ucfirst($type) ?></h4>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="category-content">
                    <?php foreach ($task_list as $task): 
                        $is_completed = $task['status'] === 'Completed';
                        $reward_claimed = $task['reward_claimed'] ?? 0;
                        $is_locked = $task['unlocked'] == 0;
                    ?>
                    <div class="task-item <?= $is_locked ? 'locked' : ($is_completed ? 'completed' : 'in-progress') ?>">
                        <div class="task-main">
                            <div class="task-info">
                                <h5><?= htmlspecialchars($task['title']) ?></h5>
                                <p><?= htmlspecialchars($task['description']) ?></p>
                            </div>
                            <div class="task-status">
                                <?php if ($is_locked): ?>
                                    <span class="status-badge">Locked 🔒</span>
                                <?php else: ?>
                                    <span class="status-badge"><?= $is_completed ? 'Completed' : 'In Progress' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="task-rewards">
                            <div class="reward-amount"><i class="fas fa-award reward-icon"></i><span><?= htmlspecialchars($task['reward']) ?></span></div>
                            <div class="task-progress"><?= htmlspecialchars($task['progress']) ?></div>
                        </div>
                        <?php if ($is_locked): ?>
                            <div class="locked-note">Complete previous task to unlock</div>
                        <?php elseif (!$is_completed): ?>
                            <div class="progress-bar"><div class="progress-fill" style="width: <?= ($task['current_value'] / $task['target_value']) * 100 ?>%"></div></div>
                        <?php elseif ($is_completed && !$reward_claimed): ?>
                                <form method="POST" class="redeem-form">
                                <input type="hidden" name="redeem_task_id" value="<?= $task['task_id'] ?>">
                                <button type="submit" class="redeem-btn">Claim Reward</button>
                                </form>

                        <?php elseif ($is_completed && $reward_claimed): ?>
                            <div class="reward-claimed"><i class="fas fa-check-circle"></i> Reward Claimed</div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>


    <div class="feedback-btn" id="feedbackBtn">💬</div>
    <div class="feedback-modal" id="feedbackModal">
        <div class="feedback-content">
            <span class="feedback-close-btn" id="feedbackCloseBtn">&times;</span>
            <div class="feedback-form" id="feedbackForm">
                <h3>Share Your Feedback</h3>
                <div class="emoji-rating" id="emojiRating">
                    <div class="emoji-option" data-rating="1"><span class="emoji">😞</span><span class="emoji-label">Very Sad</span></div>
                    <div class="emoji-option" data-rating="2"><span class="emoji">😕</span><span class="emoji-label">Sad</span></div>
                    <div class="emoji-option" data-rating="3"><span class="emoji">😐</span><span class="emoji-label">Neutral</span></div>
                    <div class="emoji-option" data-rating="4"><span class="emoji">🙂</span><span class="emoji-label">Happy</span></div>
                    <div class="emoji-option" data-rating="5"><span class="emoji">😍</span><span class="emoji-label">Very Happy</span></div>
                </div>
                <div class="error-message" id="ratingError">Please select a rating</div>
                <p class="feedback-detail">Please share in detail what we can improve more?</p>
                <textarea id="feedbackText" placeholder="Your feedback helps us make EcoWaste better..."></textarea>
                <div class="error-message" id="textError">Please provide your feedback</div>
                <button type="submit" class="feedback-submit-btn" id="feedbackSubmitBtn">
                    Submit Feedback
                    <div class="spinner" id="spinner"></div>
                </button>
            </div>
            <div class="thank-you-message" id="thankYouMessage">
                <span class="thank-you-emoji">🎉</span>
                <h3>Thank You!</h3>
                <p>We appreciate your feedback and will use it to improve EcoWaste.</p>
                <p>Your opinion matters to us!</p>
            </div>
        </div>
    </div>


<script>
    // ===== Profile Dropdown =====
    const userProfile = document.getElementById('userProfile');
    const profileDropdown = userProfile.querySelector('.profile-dropdown');

    userProfile.addEventListener('click', function(e) {
        this.classList.toggle('active');
        e.stopPropagation(); // Prevent document click from closing immediately
    });

    document.addEventListener('click', function(event) {
        if (!userProfile.contains(event.target)) {
            userProfile.classList.remove('active');
        }
    });

    // ===== Task Category Toggle =====
    function toggleCategory(header) {
    header.classList.toggle("active");
    const content = header.nextElementSibling;
    if (content.style.display === "block") {
        content.style.display = "none";
    } else {
        content.style.display = "block";
    }
}

    document.querySelectorAll('.category-header').forEach(header => {
        header.addEventListener('click', function() {
            toggleCategory(this);
        });
    });

    // Auto-expand categories with in-progress tasks
    document.querySelectorAll(".task-category").forEach(category => {
        const taskItem = category.querySelector(".task-item.in-progress");
        const content = category.querySelector(".category-content");
        const header = category.querySelector(".category-header");
        if (taskItem) {
            header.classList.add("active");
            content.style.display = "block";
        } else {
            content.style.display = "none";
        }
    });

    // Prevent task buttons/links from collapsing category
    document.querySelectorAll('.task-item button, .task-item a').forEach(el => {
        el.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });

    // ===== Feedback Modal =====
    document.addEventListener("DOMContentLoaded", function () {
    // Grab elements
    const feedbackBtn = document.getElementById("feedbackBtn");
    const feedbackModal = document.getElementById("feedbackModal");
    const feedbackCloseBtn = document.getElementById("feedbackCloseBtn");
    const emojiOptions = feedbackModal ? feedbackModal.querySelectorAll(".emoji-option") : [];
    const feedbackSubmitBtn = document.getElementById("feedbackSubmitBtn");
    const feedbackText = document.getElementById("feedbackText");
    const ratingError = document.getElementById("ratingError");
    const textError = document.getElementById("textError");
    const thankYouMessage = document.getElementById("thankYouMessage");
    const feedbackForm = document.getElementById("feedbackForm");
    const spinner = document.getElementById("spinner");

    if (!feedbackBtn || !feedbackModal || !feedbackSubmitBtn || !feedbackText) return;

    let selectedRating = 0;

    // Open modal
    feedbackBtn.addEventListener("click", () => {
        feedbackModal.style.display = "flex";
        feedbackForm.style.display = "block";
        thankYouMessage.style.display = "none";
    });

    // Close modal
    feedbackCloseBtn?.addEventListener("click", () => feedbackModal.style.display = "none");
    window.addEventListener("click", e => {
        if (e.target === feedbackModal) feedbackModal.style.display = "none";
    });

    // Emoji rating selection
    emojiOptions.forEach(option => {
        option.addEventListener("click", () => {
            emojiOptions.forEach(o => o.classList.remove("selected"));
            option.classList.add("selected");
            selectedRating = option.getAttribute("data-rating");
            ratingError.style.display = "none";
        });
    });

    // Submit feedback
    feedbackSubmitBtn.addEventListener("click", e => {
        e.preventDefault();

        let valid = true;
        if (selectedRating === 0) { ratingError.style.display = "block"; valid = false; }
        if (feedbackText.value.trim() === "") { textError.style.display = "block"; valid = false; }
        else { textError.style.display = "none"; }

        if (!valid) return;

        spinner.style.display = "inline-block";
        feedbackSubmitBtn.disabled = true;

        // AJAX POST
        fetch("feedback_process.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `rating=${selectedRating}&feedback=${encodeURIComponent(feedbackText.value)}`
        })
        .then(res => res.json())
        .then(data => {
            spinner.style.display = "none";
            feedbackSubmitBtn.disabled = false;

            if (data.status === "success") {
                feedbackForm.style.display = "none";
                thankYouMessage.style.display = "block";

                // Reset after 3 seconds
                setTimeout(() => {
                    feedbackModal.style.display = "none";
                    feedbackForm.style.display = "block";
                    thankYouMessage.style.display = "none";
                    feedbackText.value = "";
                    selectedRating = 0;
                    emojiOptions.forEach(o => o.classList.remove("selected"));
                }, 3000);
            } else {
                alert(data.message || "Failed to submit feedback.");
            }
        })
        .catch(err => {
            spinner.style.display = "none";
            feedbackSubmitBtn.disabled = false;
            alert("Failed to submit feedback. Please try again.");
            console.error(err);
        });
    });
});
</script>

</body>
</html>