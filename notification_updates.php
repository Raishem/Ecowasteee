<?php
require_once 'config.php';
require_once 'includes/notification_manager.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

if (!isset($_SESSION['user_id'])) {
    exit;
}

$notificationManager = new NotificationManager(getDBConnection());
$lastCheck = time();

while (true) {
    // Check for new notifications
    $newNotifications = $notificationManager->getNewNotifications($_SESSION['user_id'], $lastCheck);
    
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