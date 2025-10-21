<?php
session_start();
require_once 'config.php';
require_once 'includes/chat_manager.php';
require_once 'includes/notification_manager.php';

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$conn = getDBConnection();
$chatManager = new ChatManager($conn);

try {
    // Get conversation details
    $conversation = $chatManager->getConversation($conversation_id);
    if (!$conversation) {
        header('Location: projects.php');
        exit();
    }
    
    // Verify user is a participant
    if ($conversation['requester_id'] !== $user_id && $conversation['donor_id'] !== $user_id) {
        header('Location: projects.php');
        exit();
    }
    
    // Handle new message submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
        $message = trim($_POST['message']);
        if (!empty($message)) {
            $chatManager->sendMessage($conversation_id, $user_id, $message);
        }
        // Redirect to prevent form resubmission
        header("Location: chat.php?id=$conversation_id");
        exit();
    }
    
    // Get messages
    $messages = $chatManager->getMessages($conversation_id);
    
    // Mark conversation as read
    $chatManager->markAsRead($conversation_id, $user_id);
    
} catch (Exception $e) {
    $error_message = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat | EcoWaste</title>
    <link rel="stylesheet" href="assets/css/homepage.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Open+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .chat-container {
            max-width: 800px;
            margin: 20px auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            height: calc(100vh - 40px);
        }

        .chat-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
        }

        .chat-title {
            color: #2e8b57;
            margin: 0;
            font-size: 1.2rem;
        }

        .chat-subtitle {
            color: #666;
            font-size: 0.9rem;
            margin-top: 5px;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .message {
            max-width: 70%;
            padding: 10px 15px;
            border-radius: 12px;
            position: relative;
        }

        .message.sent {
            background: #e8f5e9;
            color: #2e7d32;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .message.received {
            background: #f5f5f5;
            color: #333;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }

        .message-sender {
            font-size: 0.8rem;
            margin-bottom: 4px;
            color: #666;
        }

        .message-time {
            font-size: 0.75rem;
            color: #999;
            margin-top: 4px;
        }

        .chat-input {
            padding: 20px;
            border-top: 1px solid #eee;
            background: #fff;
            border-radius: 0 0 12px 12px;
        }

        .chat-form {
            display: flex;
            gap: 10px;
        }

        .chat-form input {
            flex: 1;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }

        .chat-form button {
            background: #2e8b57;
            color: white;
            border: none;
            padding: 0 20px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .chat-form button:hover {
            background: #3cb371;
        }

        .system-message {
            text-align: center;
            color: #666;
            font-size: 0.9rem;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <h1 class="chat-title">
                Chat with <?= $conversation['requester_id'] === $user_id ? 
                          htmlspecialchars($conversation['donor_name']) : 
                          htmlspecialchars($conversation['requester_name']) ?>
            </h1>
            <div class="chat-subtitle">
                Donation status: <?= ucfirst(htmlspecialchars($conversation['status'])) ?>
            </div>
        </div>

        <div class="chat-messages" id="chatMessages">
            <?php foreach ($messages as $message): ?>
                <?php $is_sent = $message['sender_id'] === $user_id; ?>
                <div class="message <?= $is_sent ? 'sent' : 'received' ?>">
                    <?php if (!$is_sent): ?>
                        <div class="message-sender"><?= htmlspecialchars($message['sender_name']) ?></div>
                    <?php endif; ?>
                    <div class="message-content"><?= htmlspecialchars($message['message']) ?></div>
                    <div class="message-time">
                        <?= date('M j, g:i a', strtotime($message['created_at'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="chat-input">
            <form method="POST" class="chat-form">
                <input type="text" name="message" placeholder="Type your message..." required>
                <button type="submit">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
    </div>

    <script>
        // Auto-scroll to bottom on load
        const chatMessages = document.getElementById('chatMessages');
        chatMessages.scrollTop = chatMessages.scrollHeight;

        // Real-time updates using Server-Sent Events
        const evtSource = new EventSource('chat_updates.php?conversation_id=<?= $conversation_id ?>');
        
        evtSource.onmessage = function(event) {
            const message = JSON.parse(event.data);
            const isSent = message.sender_id === <?= $user_id ?>;
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
            
            let html = '';
            if (!isSent) {
                html += `<div class="message-sender">${message.sender_name}</div>`;
            }
            html += `<div class="message-content">${message.message}</div>`;
            html += `<div class="message-time">${message.created_at}</div>`;
            
            messageDiv.innerHTML = html;
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        };
        
        // Handle connection errors
        evtSource.onerror = function() {
            // debug logging removed
        };
    </script>
</body>
</html>