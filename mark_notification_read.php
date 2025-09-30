<?php
require_once 'config.php';
require_once 'includes/notification_manager.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!isset($_POST['notification_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Notification ID required']);
    exit;
}

$notificationManager = new NotificationManager(getDBConnection());
$success = $notificationManager->markAsRead($_SESSION['user_id'], $_POST['notification_id']);

echo json_encode(['success' => $success]);