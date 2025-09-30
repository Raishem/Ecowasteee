<?php
require_once 'config.php';
require_once 'includes/notification_manager.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$notificationManager = new NotificationManager(getDBConnection());

if (isset($_POST['notification_id'])) {
    // Mark single notification as read
    $success = $notificationManager->markAsRead($_SESSION['user_id'], $_POST['notification_id']);
} else {
    // Mark all notifications as read
    $success = $notificationManager->markAllAsRead($_SESSION['user_id']);
}

echo json_encode(['success' => $success]);
?>