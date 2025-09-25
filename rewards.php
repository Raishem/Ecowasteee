<?php
function runQuery($conn, $sql, $params = []) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}


require_once "config.php";


// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// --- Handle avatar purchase ---
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avatar_id'])) {
    $avatar_id = (int) $_POST['avatar_id'];

    // Fetch user coins
    $stmt = $conn->prepare("SELECT coins FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['coins'] >= 10) {
        // Deduct 10 coins
        $stmt = $conn->prepare("UPDATE users SET coins = coins - 10 WHERE id = ?");
        $stmt->execute([$user_id]);

        // Record purchase
        $stmt = $conn->prepare("INSERT INTO user_avatars (user_id, avatar_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $avatar_id]);

        $message = "🎉 Successfully redeemed avatar!";
    } else {
        $message = "❌ Not enough coins to redeem this avatar.";
    }
}

// --- Fetch user coins ---
$stmt = $conn->prepare("SELECT coins FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$coins = $user ? (int)$user['coins'] : 0;

// --- Placeholder avatars ---
$avatars = [
    ["id" => 1, "name" => "Green Leaf", "img" => "images/avatar1.png"],
    ["id" => 2, "name" => "Eco Earth", "img" => "images/avatar2.png"],
    ["id" => 3, "name" => "Recycling Icon", "img" => "images/avatar3.png"],
    ["id" => 4, "name" => "Nature Hero", "img" => "images/avatar4.png"],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Redeem Rewards</title>
    <link rel="stylesheet" href="achievement.css">
</head>
<body>
    <header>
        <h1>🎁 Reward Store</h1>
        <a href="achievements.php" class="back-btn">⬅ Back</a>
    </header>

    <main class="store-container">
        <?php if ($message): ?>
            <div class="success-message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="coins-balance">
            Your Coins: <strong><?= $coins ?></strong>
        </div>

        <div class="avatar-grid">
            <?php foreach ($avatars as $avatar): ?>
                <div class="avatar-card">
                    <img src="<?= $avatar['img'] ?>" alt="<?= htmlspecialchars($avatar['name']) ?>">
                    <h3><?= htmlspecialchars($avatar['name']) ?></h3>
                    <form method="POST">
                        <input type="hidden" name="avatar_id" value="<?= $avatar['id'] ?>">
                        <button type="submit" class="redeem-btn" <?= $coins < 10 ? "disabled" : "" ?>>
                            Redeem (10 Coins)
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
