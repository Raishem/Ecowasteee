<?php
require_once 'config.php';
require_once 'includes/notification_manager.php';

$notificationManager = new NotificationManager(getDBConnection());
$notifications = $notificationManager->getNotifications($_SESSION['user_id']);
$unread_count = $notificationManager->getUnreadCount($_SESSION['user_id']);
?>

<div class="notifications-container" id="notificationsContainer">
    <div class="notifications-icon" onclick="toggleNotifications()">
        <i class="fas fa-bell"></i>
        <?php if ($unread_count > 0): ?>
            <span class="notification-badge"><?= $unread_count ?></span>
        <?php endif; ?>
    </div>
    
    <div class="notifications-panel" id="notificationsPanel">
        <div class="notifications-header">
            <h3>Notifications</h3>
            <?php if ($unread_count > 0): ?>
                <button onclick="markAllAsRead()" class="mark-all-read">Mark all as read</button>
            <?php endif; ?>
        </div>
        
        <div class="notifications-list">
            <?php if (empty($notifications)): ?>
                <div class="no-notifications">
                    No notifications
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>" 
                         data-id="<?= $notification['notification_id'] ?>">
                        <div class="notification-icon">
                            <?php switch ($notification['type']):
                                case 'donation_request': ?>
                                    <i class="fas fa-hand-holding-heart"></i>
                                    <?php break; ?>
                                case 'donation_offer': ?>
                                    <i class="fas fa-gift"></i>
                                    <?php break; ?>
                                case 'message': ?>
                                    <i class="fas fa-envelope"></i>
                                    <?php break; ?>
                                default: ?>
                                    <i class="fas fa-bell"></i>
                            <?php endswitch; ?>
                        </div>
                        <div class="notification-content">
                            <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                            <div class="notification-message"><?= htmlspecialchars($notification['message']) ?></div>
                            <div class="notification-time">
                                <?= date('M j, g:i a', strtotime($notification['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.notifications-container {
    position: relative;
    margin-right: 20px;
}

.notifications-icon {
    cursor: pointer;
    position: relative;
    padding: 8px;
}

.notification-badge {
    position: absolute;
    top: 0;
    right: 0;
    background: #f44336;
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 0.75rem;
    min-width: 18px;
    text-align: center;
}

.notifications-panel {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    width: 320px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    max-height: 480px;
    overflow-y: auto;
    z-index: 1000;
}

.notifications-panel.active {
    display: block;
}

.notifications-header {
    padding: 15px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notifications-header h3 {
    margin: 0;
    color: #333;
    font-size: 1.1rem;
}

.mark-all-read {
    background: none;
    border: none;
    color: #2e8b57;
    cursor: pointer;
    font-size: 0.9rem;
}

.notifications-list {
    padding: 10px 0;
}

.notification-item {
    display: flex;
    padding: 12px 15px;
    border-bottom: 1px solid #f5f5f5;
    cursor: pointer;
    transition: background-color 0.2s;
}

.notification-item:hover {
    background-color: #f9f9f9;
}

.notification-item.unread {
    background-color: #f0f7f0;
}

.notification-icon {
    margin-right: 12px;
    color: #2e8b57;
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-weight: 500;
    color: #333;
    margin-bottom: 4px;
}

.notification-message {
    color: #666;
    font-size: 0.9rem;
    margin-bottom: 4px;
}

.notification-time {
    color: #999;
    font-size: 0.8rem;
}

.no-notifications {
    padding: 20px;
    text-align: center;
    color: #666;
}
</style>

<script>
function toggleNotifications() {
    const panel = document.getElementById('notificationsPanel');
    panel.classList.toggle('active');
}

function markAllAsRead() {
    fetch('mark_notifications_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.notification-item').forEach(item => {
                item.classList.remove('unread');
            });
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                badge.remove();
            }
        }
    });
}

// Close notifications panel when clicking outside
document.addEventListener('click', function(event) {
    const container = document.getElementById('notificationsContainer');
    const panel = document.getElementById('notificationsPanel');
    
    if (!container.contains(event.target)) {
        panel.classList.remove('active');
    }
});

// Handle notification click
document.querySelectorAll('.notification-item').forEach(item => {
    item.addEventListener('click', function() {
        const id = this.dataset.id;
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: id })
        });
        this.classList.remove('unread');
    });
});

// Real-time updates
const evtSource = new EventSource('notification_updates.php');
evtSource.onmessage = function(event) {
    const notification = JSON.parse(event.data);
    addNewNotification(notification);
};

function addNewNotification(notification) {
    const list = document.querySelector('.notifications-list');
    const noNotifications = list.querySelector('.no-notifications');
    if (noNotifications) {
        noNotifications.remove();
    }
    
    const div = document.createElement('div');
    div.className = 'notification-item unread';
    div.dataset.id = notification.notification_id;
    
    div.innerHTML = `
        <div class="notification-icon">
            <i class="fas fa-${getNotificationIcon(notification.type)}"></i>
        </div>
        <div class="notification-content">
            <div class="notification-title">${notification.title}</div>
            <div class="notification-message">${notification.message}</div>
            <div class="notification-time">Just now</div>
        </div>
    `;
    
    list.insertBefore(div, list.firstChild);
    updateNotificationBadge(1);
}

function getNotificationIcon(type) {
    switch(type) {
        case 'donation_request': return 'hand-holding-heart';
        case 'donation_offer': return 'gift';
        case 'message': return 'envelope';
        default: return 'bell';
    }
}

function updateNotificationBadge(increment) {
    let badge = document.querySelector('.notification-badge');
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'notification-badge';
        document.querySelector('.notifications-icon').appendChild(badge);
    }
    const currentCount = parseInt(badge.textContent || '0');
    badge.textContent = currentCount + increment;
}
</script>