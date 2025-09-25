<?php
// achievements.php - fully rewritten to use PDO (getDBConnection) and safe helpers
// Requires: config.php (must define getDBConnection(), generateCSRFToken(), verifyCSRFToken())
session_start();
require_once 'config.php';

$pdo = getDBConnection();
if (!$pdo) {
    die("Database connection error");
}

// CSRF token
$csrf = generateCSRFToken();

// Game economy config
define('POINTS_PER_LEVEL', 100); // points required per level
define('COINS_PER_LEVEL', 10);   // coins reward per level claimed

// Auth check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$user_id = (int) $_SESSION['user_id'];

// --- PDO helper functions ---
function fetchOne($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fetchAll($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function executeQuery($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

// -----------------------
// Ensure user_stats row exists
// -----------------------
$existingStats = fetchOne($pdo, "SELECT * FROM user_stats WHERE user_id = ?", [$user_id]);
if (!$existingStats) {
    executeQuery($pdo, "INSERT INTO user_stats (user_id, items_recycled, items_donated, projects_created, projects_completed, total_points, achievements_earned, badges_earned) VALUES (?, 0, 0, 0, 0, 0, 0, 0)", [$user_id]);
    $existingStats = fetchOne($pdo, "SELECT * FROM user_stats WHERE user_id = ?", [$user_id]);
}

// -----------------------
// Seed default tasks for user if none exist
// -----------------------
$countRow = fetchOne($pdo, "SELECT COUNT(*) AS cnt FROM user_tasks WHERE user_id = ?", [$user_id]);
$taskCount = (int) ($countRow['cnt'] ?? 0);

if ($taskCount === 0) {
    $seed = [
        [1,'Rising Donor','Complete your first donation','20 EcoPoints','points',20,0,1,'items_donated'],
        [1,'Helpful Friend','Complete 10 donations','50 EcoPoints','points',50,0,10,'items_donated'],
        [1,'Care Giver','Complete 15 donations','100 EcoPoints','points',100,0,15,'items_donated'],
        [1,'Generous Giver','Complete 20 donations','150 EcoPoints','points',150,0,20,'items_donated'],
        [1,'Community Helper','Complete 25 donations','200 EcoPoints','points',200,0,25,'items_donated'],
        [1,'Charity Champion','Complete 30 donations','250 EcoPoints','points',250,0,30,'items_donated'],

        [2,'Eco Beginner','Start your first recycling project','20 EcoPoints','points',20,0,1,'projects_created'],
        [2,'Eco Builder','Create 10 recycling projects','50 EcoPoints','points',50,0,10,'projects_created'],
        [2,'Nature Keeper','Create 15 recycling projects','100 EcoPoints','points',100,0,15,'projects_created'],
        [2,'Conservation Expert','Create 20 recycling projects','150 EcoPoints','points',150,0,20,'projects_created'],
        [2,'Zero Waste Hero','Create 25 recycling projects','200 EcoPoints','points',200,0,25,'projects_created'],
        [2,'Earth Saver','Create 30 recycling projects','250 EcoPoints','points',250,0,30,'projects_created'],

        [3,'Eco Star','Complete a recycling project','20 EcoPoints','points',20,0,1,'projects_completed'],
        [3,'Eco Warrior','Complete 10 recycling projects','50 EcoPoints','points',50,0,10,'projects_completed'],
        [3,'Eco Elite','Complete 15 recycling projects','100 EcoPoints','points',100,0,15,'projects_completed'],
        [3,'Eco Pro','Complete 20 recycling projects','150 EcoPoints','points',150,0,20,'projects_completed'],
        [3,'Eco Master','Complete 25 recycling projects','200 EcoPoints','points',200,0,25,'projects_completed'],
        [3,'Eco Legend','Complete 30 recycling projects','250 EcoPoints','points',250,0,30,'projects_completed'],
    ];

    $insertSql = "INSERT INTO user_tasks (user_id, section, title, description, reward, reward_type, reward_value, progress, current_value, target_value, task_type, action_type, status, reward_claimed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'In Progress', 0)";
    $stmtInsert = $pdo->prepare($insertSql);

    foreach ($seed as $row) {
        list($section, $title, $description, $rewardLabel, $rewardType, $rewardValue, $currentValue, $targetValue, $actionType) = $row;
        // store progress column as rewardLabel for display (or adjust as needed)
        $stmtInsert->execute([$user_id, $section, $title, $description, $rewardLabel, $rewardType, $rewardValue, $rewardLabel, $currentValue, $targetValue, $actionType, $actionType]);
    }
    // no explicit close needed for PDO
}

// -----------------------
// Refresh & load user, stats, tasks
// -----------------------
$user = fetchOne($pdo, "SELECT user_id, first_name, points, level, coins, last_claimed_level FROM users WHERE user_id = ?", [$user_id]);
if (!$user) {
    $user = [
        'user_id' => $user_id,
        'first_name' => $_SESSION['first_name'] ?? 'User',
        'points' => 0,
        'level' => 1,
        'coins' => 0,
        'last_claimed_level' => 0
    ];
}

$stats = fetchOne($pdo, "SELECT * FROM user_stats WHERE user_id = ?", [$user_id]);

// authoritative total_points (already kept in stats)
$items_recycled = (int) ($stats['items_recycled'] ?? 0);
$items_donated = (int) ($stats['items_donated'] ?? 0);
$total_points = (int) ($stats['total_points'] ?? 0);

// persist total_points in user_stats (and increment achievements_earned if desired)
executeQuery($pdo, "UPDATE user_stats SET total_points = ?, achievements_earned = achievements_earned + 1 WHERE user_id = ?", [$total_points, $user_id]);

// level/coins
$pointsPerLevel = POINTS_PER_LEVEL;
$coinsPerLevel  = COINS_PER_LEVEL;
$calculated_level = (int) floor($total_points / $pointsPerLevel) + 1;
$pending_claims = max(0, $calculated_level - (int)($user['last_claimed_level'] ?? 0));

// Fetch user tasks ordered
$tasks = fetchAll($pdo, "SELECT * FROM user_tasks WHERE user_id = ? ORDER BY section ASC, task_id ASC", [$user_id]);


// split into sections
$sectionTasks = [1=>[],2=>[],3=>[]];
foreach ($tasks as $t) {
    $sec = (int)($t['section'] ?? 1);
    $sectionTasks[$sec][] = $t;
}

// visible map helper
function visibleMapForSection($tasksForSection) {
    $map = [];
    $prevClaimed = true;
    foreach ($tasksForSection as $idx => $task) {
        if ($idx === 0) {
            $map[$idx] = true;
            $prevClaimed = ((int)($task['reward_claimed'] ?? 0) === 1);
            continue;
        }
        $map[$idx] = $prevClaimed ? true : false;
        $prevClaimed = ((int)($task['reward_claimed'] ?? 0) === 1);
    }
    return $map;
}
$visibleMap = [
    1 => visibleMapForSection($sectionTasks[1]),
    2 => visibleMapForSection($sectionTasks[2]),
    3 => visibleMapForSection($sectionTasks[3]),
];

// -----------------------
// POST handling
// -----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Redeem task reward
    if (isset($_POST['redeem_task']) && isset($_POST['user_task_id'])) {
        // optional: CSRF protection (if you included csrf hidden input in forms)
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($token)) {
            $_SESSION['error_message'] = "Invalid request (CSRF).";
            header("Location: achievements.php");
            exit();
        }

        $user_task_id = intval($_POST['user_task_id']);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM user_tasks WHERE task_id = ? AND user_id = ? FOR UPDATE");
            $stmt->execute([$user_task_id, $user_id]);
            $taskRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($taskRow) {
                $reward_claimed = (int)($taskRow['reward_claimed'] ?? 0);
                $isCompleted = in_array(strtolower($taskRow['status']), ['completed', 'complete', 'done']) || ((int)$taskRow['current_value'] >= (int)$taskRow['target_value']);

                if ($isCompleted && $reward_claimed === 0) {
                    $reward_type = $taskRow['reward_type'] ?? 'points';
                    $reward_value = (int)($taskRow['reward_value'] ?? 0);

                    if ($reward_type === 'points') {
                        executeQuery($pdo, "UPDATE user_stats SET total_points = total_points + ? WHERE user_id = ?", [$reward_value, $user_id]);

                        // update users.points if column exists
                        $colCheck = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'points'");
                        $colCheck->execute();
                        if ($colCheck->fetch(PDO::FETCH_ASSOC)) {
                            executeQuery($pdo, "UPDATE users SET points = points + ? WHERE user_id = ?", [$reward_value, $user_id]);
                        }
                    } elseif ($reward_type === 'badge') {
                        $badgeName = is_numeric($reward_value) ? ("Badge #".$reward_value) : ($taskRow['reward'] ?? 'Badge');
                        executeQuery($pdo, "INSERT INTO user_badges (user_id, badge_name, earned_at) VALUES (?, ?, NOW())", [$user_id, $badgeName]);

                        $colCheck = $pdo->prepare("SHOW COLUMNS FROM user_stats LIKE 'badges_earned'");
                        $colCheck->execute();
                        if ($colCheck->fetch(PDO::FETCH_ASSOC)) {
                            executeQuery($pdo, "UPDATE user_stats SET badges_earned = badges_earned + 1 WHERE user_id = ?", [$user_id]);
                        }
                    }

                    executeQuery($pdo, "UPDATE user_tasks SET reward_claimed = 1 WHERE task_id = ? AND user_id = ?", [$user_task_id, $user_id]);

                    // unlock next task in same section (by insertion order)
                    $section = (int)($taskRow['section'] ?? 1);
                    $stmt2 = $pdo->prepare("SELECT task_id FROM user_tasks WHERE user_id = ? AND section = ? AND task_id > ? ORDER BY task_id ASC LIMIT 1");
                    $stmt2->execute([$user_id, $section, $user_task_id]);
                    $next = $stmt2->fetch(PDO::FETCH_ASSOC);
                    if ($next) {
                        executeQuery($pdo, "UPDATE user_tasks SET status = 'In Progress' WHERE task_id = ? AND user_id = ?", [$next['task_id'], $user_id]);
                    }

                    $_SESSION['success_message'] = "Task reward claimed!";
                } else {
                    $_SESSION['error_message'] = "Task is not claimable or already claimed.";
                }
            } else {
                $_SESSION['error_message'] = "Task not found.";
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Could not claim task reward: " . $e->getMessage();
        }

        header("Location: achievements.php");
        exit();
    }

    // Claim level reward
    if (isset($_POST['claim_level']) && isset($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
        if (!verifyCSRFToken($token)) {
            $_SESSION['error_message'] = "Invalid request (CSRF).";
            header("Location: achievements.php");
            exit();
        }

        $statsRow = fetchOne($pdo, "SELECT total_points FROM user_stats WHERE user_id = ?", [$user_id]);
        $current_total_points = (int)($statsRow['total_points'] ?? 0);
        $calc_level = (int) floor($current_total_points / $pointsPerLevel) + 1;
        $last_claimed = (int) ($user['last_claimed_level'] ?? 0);

        if ($calc_level > $last_claimed) {
            $level_to_claim = $last_claimed + 1;
            $pdo->beginTransaction();
            try {
                executeQuery($pdo, "UPDATE users SET coins = coins + ?, last_claimed_level = ?, level = GREATEST(level, ?) WHERE user_id = ?", [$coinsPerLevel, $level_to_claim, $level_to_claim, $user_id]);
                $pdo->commit();
                $_SESSION['success_message'] = "Claimed level $level_to_claim reward (+{$coinsPerLevel} coins).";
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['error_message'] = "Could not claim level reward: " . $e->getMessage();
            }
        } else {
            $_SESSION['error_message'] = "No level reward available to claim.";
        }

        header("Location: achievements.php");
        exit();
    }

    // Claim section badge
    if (isset($_POST['claim_section_badge']) && isset($_POST['section']) && isset($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
        if (!verifyCSRFToken($token)) {
            $_SESSION['error_message'] = "Invalid request (CSRF).";
            header("Location: achievements.php");
            exit();
        }

        $section = intval($_POST['section']);
        $row = fetchOne($pdo, "SELECT COUNT(*) AS not_claimed FROM user_tasks WHERE user_id = ? AND section = ? AND reward_claimed = 0", [$user_id, $section]);
        $not_claimed = (int)($row['not_claimed'] ?? 0);

        if ($not_claimed === 0) {
            $badgeName = ($section === 1) ? 'Charity Champion Badge' : (($section === 2) ? 'Earth Saver Badge' : 'Eco Legend Badge');
            $r = fetchOne($pdo, "SELECT COUNT(*) AS cnt FROM user_badges WHERE user_id = ? AND badge_name = ?", [$user_id, $badgeName]);
            if ((int)($r['cnt'] ?? 0) === 0) {
                $pdo->beginTransaction();
                try {
                    executeQuery($pdo, "INSERT INTO user_badges (user_id, badge_name, earned_at) VALUES (?, ?, NOW())", [$user_id, $badgeName]);

                    $colCheck = $pdo->prepare("SHOW COLUMNS FROM user_stats LIKE 'badges_earned'");
                    $colCheck->execute();
                    if ($colCheck->fetch(PDO::FETCH_ASSOC)) {
                        executeQuery($pdo, "UPDATE user_stats SET badges_earned = badges_earned + 1 WHERE user_id = ?", [$user_id]);
                    }

                    $pdo->commit();
                    $_SESSION['success_message'] = "Badge awarded: $badgeName";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $_SESSION['error_message'] = "Could not award badge: " . $e->getMessage();
                }
            } else {
                $_SESSION['error_message'] = "Badge already claimed.";
            }
        } else {
            $_SESSION['error_message'] = "You must claim all tasks in this section to get the badge.";
        }

        header("Location: achievements.php");
        exit();
    }
} // end POST handling

// Re-load data for display
$user = fetchOne($pdo, "SELECT user_id, first_name, points, level, coins, last_claimed_level FROM users WHERE user_id = ?", [$user_id]);
$stats = fetchOne($pdo, "SELECT * FROM user_stats WHERE user_id = ?", [$user_id]);
$tasks = fetchAll($pdo, "SELECT * FROM user_tasks WHERE user_id = ? ORDER BY section ASC, task_id ASC", [$user_id]);

$sectionTasks = [1=>[],2=>[],3=>[]];
foreach ($tasks as $t) {
    $sec = (int)($t['section'] ?? 1);
    $sectionTasks[$sec][] = $t;
}

// computed values for display
$items_recycled = (int) ($stats['items_recycled'] ?? 0);
$items_donated = (int) ($stats['items_donated'] ?? 0);
$total_points = (int) ($stats['total_points'] ?? 0);
$calculated_level = (int) floor($total_points / $pointsPerLevel) + 1;
$pending_claims = max(0, $calculated_level - (int)($user['last_claimed_level'] ?? 0));

$currentLevelBasePoints = ($calculated_level - 1) * $pointsPerLevel;
$progressWithin = $total_points - $currentLevelBasePoints;
$percentToNext = ($pointsPerLevel > 0) ? max(0, min(100, ($progressWithin / $pointsPerLevel) * 100)) : 0;
$circ = 2 * pi() * 90;
$offset = $circ - ($circ * $percentToNext / 100);

$user_badges = fetchAll($pdo, "SELECT * FROM user_badges WHERE user_id = ? ORDER BY earned_at DESC", [$user_id]);

function sectionAllClaimed($tasks) {
    foreach ($tasks as $t) {
        if (((int)($t['reward_claimed'] ?? 0)) === 0) return false;
    }
    return count($tasks) > 0;
}

// escape helper
function h($s) { return htmlspecialchars($s ?? ''); }

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Achievements | EcoWaste</title>

    <!-- CDNs for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <!-- Your local stylesheet -->
    <link rel="stylesheet" href="assets/css/achievement.css">


    <style>
        /* small inline style adjustments for modal overlays (keeps main CSS external) */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); align-items:center; justify-content:center; z-index:2500; }
        .modal { width:90%; max-width:900px; background:white; border-radius:10px; padding:18px; max-height:80vh; overflow:auto; box-shadow:0 8px 30px rgba(0,0,0,0.25); }
        .task-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap:14px; }
        .badge-box { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px; border-radius:8px; background:#f6fff0; }
    </style>
</head>
<body>
    <header>
        <div class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fas fa-bars"></i></div>
        <div class="logo-container">
            <div class="logo"><img src="assets/img/ecowaste_logo.png" alt="EcoWaste Logo"></div>
            <h1>EcoWaste</h1>
        </div>
        <div class="user-profile" id="userProfile">
            <div class="profile-pic"><?= strtoupper(substr(h($_SESSION['first_name'] ?? $user['first_name'] ?? 'U'),0,1)) ?></div>
            <span class="profile-name"><?= h($_SESSION['first_name'] ?? $user['first_name'] ?? 'User') ?></span>
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
                    <li><a href="achievements.php" style="color: rgb(4, 144, 4);"><i class="fas fa-star"></i>Achievements</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i>Leaderboard</a></li>
                    <li><a href="projects.php"><i class="fas fa-recycle"></i>Projects</a></li>
                    <li><a href="donations.php"><i class="fas fa-box"></i>Donations</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <div class="achievements-header">
                <h2>My Achievements</h2>
                <p class="subtitle">Track your eco-friendly progress and accomplishments</p>
            </div>

            <?php if (!empty($_SESSION['success_message'])): ?>
                <div class="success-message"><?= h($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error_message'])): ?>
                <div class="success-message" style="background:#f8d7da;color:#842029;border-left-color:#f5c2c7;"><?= h($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <div class="achievements-content">
                <div class="level-card">
                    <div class="circular-progress">
                        <svg class="progress-ring" width="200" height="200">
                            <circle class="progress-ring-circle" r="90" cx="100" cy="100"></circle>
                            <circle class="progress-ring-progress" r="90" cx="100" cy="100"
                                stroke-dasharray="<?= htmlspecialchars($circ) ?>"
                                stroke-dashoffset="<?= htmlspecialchars($offset) ?>"/>
                        </svg>
                        <div class="circle">
                            <div class="circle-inner">
                                <div class="level-number"><?= h($user['level']) ?></div>
                                <div class="level-label">LEVEL</div>
                            </div>
                        </div>
                    </div>

                    <div class="progress-text"><?= h($total_points) ?> Points</div>

                    <form method="post" style="margin-top:10px;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <button type="submit" name="claim_level" class="redeem-btn" <?= $pending_claims <= 0 ? 'disabled' : '' ?>>
                            Claim (+<?= $coinsPerLevel ?> coins)
                        </button>
                    </form>

                    <div class="current-level" style="margin-top:8px;">
                        <?php if ($pending_claims > 0): ?>
                            You have <?= $pending_claims ?> unclaimed level reward(s).
                        <?php else: ?>
                            Level <?= h($user['level']) ?> • Next: <?= (($user['level'])*$pointsPerLevel) - $total_points ?> points
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:flex; justify-content:center; margin-bottom:18px;">
                    <a href="rewards.php" class="redeem-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                        <i class="fas fa-gift"></i> Redeem Rewards
                    </a>
                </div>

                <div class="stats-grid">
                    <div class="stat-item"><div class="stat-number"><?= h($stats['projects_completed'] ?? 0) ?></div><div class="stat-label">Projects Completed</div></div>
                    <div class="stat-item"><div class="stat-number"><?= h($stats['achievements_earned'] ?? 0) ?></div><div class="stat-label">Achievements Earned</div></div>
                    <div class="stat-item"><div class="stat-number"><?= h($stats['badges_earned'] ?? 0) ?></div><div class="stat-label">Badges Earned</div></div>
                    <div class="stat-item"><div class="stat-number"><?= h($stats['items_donated'] ?? 0) ?></div><div class="stat-label">Total Items Donated</div></div>
                    <div class="stat-item"><div class="stat-number"><?= h($stats['items_recycled'] ?? 0) ?></div><div class="stat-label">Total Items Recycled</div></div>
                </div>

                <?php for ($section = 1; $section <= 3; $section++):
                    $secTasks = $sectionTasks[$section];
                    $visible = $visibleMap[$section];
                    if ($section === 1) { $sectionTitle = "Donation Tasks"; $viewAllText = "Complete all tasks to claim the Charity Champion Badge!"; $badgeName="Charity Champion Badge"; }
                    elseif ($section === 2) { $sectionTitle = "Project Creation Tasks"; $viewAllText = "Complete all tasks to claim the Earth Saver Badge!"; $badgeName="Earth Saver Badge"; }
                    else { $sectionTitle = "Project Completion Tasks"; $viewAllText = "Complete all tasks to claim the Eco Legend Badge!"; $badgeName="Eco Legend Badge"; }
                ?>
                <div class="tasks-section">
                    <div class="tasks-header">
                        <h3><?= h($sectionTitle) ?></h3>
                        <button class="redeem-btn" type="button" onclick="openModal(<?= $section ?>)">View All</button>
                    </div>
                    <p class="tasks-subtitle"><?= ($section===1 ? 'Donation progression' : ($section===2 ? 'Create projects progression' : 'Complete projects progression')) ?></p>

                    <div class="task-list" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px;">
                        <?php foreach ($secTasks as $idx => $t):
                            $is_visible = $visible[$idx] ?? false;
                            $is_completed = in_array(strtolower($t['status']), ['completed','complete']) || ((int)$t['current_value'] >= (int)$t['target_value']);
                            $claimed = ((int)($t['reward_claimed'] ?? 0)) === 1;
                            $progress_text = $t['progress'] ?? (($t['current_value'] ?? 0) . '/' . ($t['target_value'] ?? 1));
                            $curr = (int)($t['current_value'] ?? 0);
                            $targ = max(1, (int)($t['target_value'] ?? 1));
                            $progress_percent = ($targ>0) ? min(100, round(($curr/$targ)*100)) : 0;
                        ?>
                        <div class="task-item <?= $is_completed ? 'completed' : 'in-progress' ?>" style="<?= $is_visible ? '' : 'opacity:0.45; filter:grayscale(0.08);' ?>">
                            <div class="task-main">
                                <div class="task-info">
                                    <h4><?= h($t['title']) ?></h4>
                                    <p><?= h($t['description']) ?></p>
                                </div>
                                <div class="task-status">
                                    <span class="status-badge"><?= $is_completed ? 'Completed' : 'In Progress' ?></span>
                                </div>
                            </div>

                            <div class="task-rewards" style="margin-top:12px;">
                                <div class="reward-amount"><i class="fas fa-award reward-icon"></i><span><?= h($t['reward']) ?></span></div>
                                <div class="task-progress"><?= h($progress_text) ?></div>
                            </div>

                            <?php if (!$is_visible): ?>
                                <div style="margin-top:12px; color:#999;">Locked — finish previous task & claim reward to unlock</div>
                            <?php elseif ($is_completed && !$claimed): ?>
                                <form method="post" class="redeem-form" style="margin-top:12px;">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="user_task_id" value="<?= (int)$t['user_task_id'] ?>">
                                    <button type="submit" name="redeem_task" class="redeem-btn">Claim <?= h($t['reward']) ?></button>
                                </form>
                            <?php elseif ($is_completed && $claimed): ?>
                                <div class="reward-claimed" style="margin-top:12px;"><i class="fas fa-check-circle"></i> Reward Claimed</div>
                            <?php endif; ?>

                            <?php if (!$is_completed): ?>
                                <div class="progress-bar" style="margin-top:12px;">
                                    <div class="progress-fill" style="width: <?= $progress_percent ?>%"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endfor; ?>

            </div>
        </main>
    </div>

    <!-- View All modals -->
    <?php for ($section = 1; $section <= 3; $section++):
        if ($section === 1) { $title="Donation Tasks"; $viewAllText = "Complete all tasks to claim the Charity Champion Badge!"; $badgeName="Charity Champion Badge";}
        elseif ($section === 2) { $title="Project Creation Tasks"; $viewAllText = "Complete all tasks to claim the Earth Saver Badge!"; $badgeName="Earth Saver Badge";}
        else { $title="Project Completion Tasks"; $viewAllText = "Complete all tasks to claim the Eco Legend Badge!"; $badgeName="Eco Legend Badge";}
        $secTasks = $sectionTasks[$section];
        $allClaimed = sectionAllClaimed($secTasks);
    ?>
    <div id="modal-<?= $section ?>" class="modal-overlay" aria-hidden="true" role="dialog">
        <div class="modal">
            <button onclick="closeModal(<?= $section ?>)" style="float:right; background:transparent; border:none; font-size:20px; cursor:pointer;">&times;</button>
            <h3><?= h($title) ?></h3>
            <div class="task-grid" style="margin-top:12px;">
                <?php foreach ($secTasks as $t):
                    $is_completed = in_array(strtolower($t['status']), ['completed','complete']) || ((int)$t['current_value'] >= (int)$t['target_value']);
                    $claimed = ((int)($t['reward_claimed'] ?? 0)) === 1;
                    $progress_text = $t['progress'] ?? (($t['current_value'] ?? 0) . '/' . ($t['target_value'] ?? 1));
                ?>
                    <div class="task-item <?= $is_completed ? 'completed' : 'in-progress' ?>">
                        <div class="task-info">
                            <h4><?= h($t['title']) ?></h4>
                            <p><?= h($t['description']) ?></p>
                            <p style="margin-top:8px; font-weight:600;"><?= h($progress_text) ?> • <?= h($t['reward']) ?></p>
                        </div>
                        <?php if ($is_completed && !$claimed): ?>
                            <form method="post" style="margin-top:8px;">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="user_task_id" value="<?= (int)$t['user_task_id'] ?>">
                                <button type="submit" name="redeem_task" class="redeem-btn">Claim <?= h($t['reward']) ?></button>
                            </form>
                        <?php elseif ($is_completed && $claimed): ?>
                            <div class="reward-claimed" style="margin-top:8px;"><i class="fas fa-check-circle"></i> Reward Claimed</div>
                        <?php else: ?>
                            <div style="margin-top:8px; color:#777;">Not yet completed</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:16px;" class="badge-box">
                <div>
                    <strong><?= h($badgeName) ?></strong>
                    <div style="font-size:13px; color:#666; margin-top:6px;"><?= h($viewAllText) ?></div>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="text-align:center;">
                        <div style="width:64px;height:64px;border-radius:8px;background:#f0f7e8; display:flex; align-items:center; justify-content:center; font-weight:700; color:#2e8b57;">🏅</div>
                        <div style="font-size:12px; margin-top:6px;">Badge</div>
                    </div>

                    <form method="post">
                        <input type="hidden" name="section" value="<?= $section ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <button type="submit" name="claim_section_badge" class="redeem-btn" <?= $allClaimed ? '' : 'disabled' ?>>Claim Badge</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endfor; ?>

    <!-- Feedback button + modal -->
    <div class="feedback-btn" id="feedbackBtn" role="button" aria-label="Feedback">💬</div>
    <div class="feedback-modal" id="feedbackModal" aria-hidden="true">
        <div class="feedback-content">
            <span class="feedback-close-btn" id="feedbackCloseBtn" role="button" aria-label="Close">&times;</span>
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
document.addEventListener('DOMContentLoaded', function() {
    // profile dropdown
    const userProfile = document.getElementById('userProfile');
    if (userProfile) {
        userProfile.addEventListener('click', function(e) {
            this.classList.toggle('active');
            e.stopPropagation();
        });
        document.addEventListener('click', function(event) {
            if (!userProfile.contains(event.target)) userProfile.classList.remove('active');
        });
    }

    // mobile menu toggle
    const mobileToggle = document.getElementById('mobileMenuToggle');
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function() {
            const sidebar = document.querySelector('.sidebar');
            if (!sidebar) return;
            sidebar.classList.toggle('active');
            const icon = this.querySelector('i');
            if (!icon) return;
            if (sidebar.classList.contains('active')) { icon.classList.remove('fa-bars'); icon.classList.add('fa-times'); }
            else { icon.classList.remove('fa-times'); icon.classList.add('fa-bars'); }
        });
    }

    // Modal open/close helpers
    window.openModal = function(section) {
        const modal = document.getElementById('modal-' + section);
        if (modal) modal.style.display = 'flex';
    };
    window.closeModal = function(section) {
        const modal = document.getElementById('modal-' + section);
        if (modal) modal.style.display = 'none';
    };
    window.addEventListener('click', (event) => {
        for (let s=1; s<=3; s++) {
            const modal = document.getElementById('modal-' + s);
            if (modal && event.target === modal) closeModal(s);
        }
    });

    // Feedback modal logic
    (function() {
        const feedbackBtn = document.getElementById('feedbackBtn');
        const feedbackModal = document.getElementById('feedbackModal');
        const feedbackCloseBtn = document.getElementById('feedbackCloseBtn');
        const emojiOptions = document.querySelectorAll('.emoji-option');
        const feedbackForm = document.getElementById('feedbackForm');
        const thankYouMessage = document.getElementById('thankYouMessage');
        const feedbackSubmitBtn = document.getElementById('feedbackSubmitBtn');
        const spinner = document.getElementById('spinner');
        const ratingError = document.getElementById('ratingError');
        const textError = document.getElementById('textError');
        const feedbackText = document.getElementById('feedbackText');
        let selectedRating = 0;

        if (!feedbackBtn || !feedbackModal) return;

        emojiOptions.forEach(option => {
            option.addEventListener('click', () => {
                emojiOptions.forEach(opt => opt.classList.remove('selected'));
                option.classList.add('selected');
                selectedRating = parseInt(option.getAttribute('data-rating') || '0', 10);
                if (ratingError) ratingError.style.display = 'none';
            });
        });

        // Use the button as a trigger - not a real POST to server (client-side demo)
        feedbackSubmitBtn.addEventListener('click', function(e) {
            // validate
            let isValid = true;
            if (selectedRating === 0) {
                if (ratingError) ratingError.style.display = 'block';
                isValid = false;
            } else if (ratingError) ratingError.style.display = 'none';
            if (!feedbackText.value || feedbackText.value.trim() === '') {
                if (textError) textError.style.display = 'block';
                isValid = false;
            } else if (textError) textError.style.display = 'none';
            if (!isValid) return;
            feedbackSubmitBtn.disabled = true;
            if (spinner) spinner.style.display = 'block';
            setTimeout(() => {
                if (spinner) spinner.style.display = 'none';
                if (feedbackForm) feedbackForm.style.display = 'none';
                if (thankYouMessage) thankYouMessage.style.display = 'block';
                setTimeout(() => {
                    if (feedbackModal) feedbackModal.style.display = 'none';
                    if (feedbackForm) feedbackForm.style.display = 'block';
                    if (thankYouMessage) thankYouMessage.style.display = 'none';
                    feedbackText.value = '';
                    emojiOptions.forEach(opt => opt.classList.remove('selected'));
                    selectedRating = 0;
                    feedbackSubmitBtn.disabled = false;
                }, 2000);
            }, 1000);
        });

        feedbackBtn.addEventListener('click', () => { if (feedbackModal) feedbackModal.style.display = 'flex'; });
        feedbackCloseBtn.addEventListener('click', () => { if (feedbackModal) feedbackModal.style.display = 'none'; });
        window.addEventListener('click', (event) => { if (event.target === feedbackModal) feedbackModal.style.display = 'none'; });
    })();
}); // DOMContentLoaded
</script>

<div class="sidebar-overlay"></div>
</body>
</html>
