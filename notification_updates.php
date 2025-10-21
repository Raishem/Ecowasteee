<?php
session_start();
require_once 'config.php';
require_once 'includes/notification_manager.php';

// Support a one-shot JSON fetch for dashboard/header use: ?action=get_recent
if (isset($_GET['action']) && $_GET['action'] === 'get_recent') {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }
    $nm = new NotificationManager(getDBConnection());
    $unread = (int)$nm->getUnreadCount($_SESSION['user_id']);
    $recent = $nm->getNotifications($_SESSION['user_id'], 8);
    echo json_encode(['success'=>true,'unread_count'=>$unread,'notifications'=>$recent]);
    exit;
}

// Otherwise act as an SSE endpoint
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

if (empty($_SESSION['user_id'])) {
    exit;
}

$notificationManager = new NotificationManager(getDBConnection());
$lastCheck = time();

while (true) {
    // Check for new notifications by fetching recent ones and filtering by created_at
    $recent = $notificationManager->getNotifications($_SESSION['user_id'], 20);
    $newNotifications = [];
    foreach ($recent as $n) {
        $ts = isset($n['created_at']) ? strtotime($n['created_at']) : 0;
        if ($ts > $lastCheck) $newNotifications[] = $n;
    }

    if (!empty($newNotifications)) {
        foreach ($newNotifications as $notification) {
            echo "data: " . json_encode($notification) . "\n\n";
            flush();
        }
    }

    $lastCheck = time();
    sleep(5); // Check every 5 seconds
}
?>