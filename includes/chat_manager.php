<?php
class ChatManager {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function createConversation($donation_id, $participants) {
        $this->conn->beginTransaction();
        
        try {
            // Create conversation
            $stmt = $this->conn->prepare("
                INSERT INTO chat_conversations (donation_id)
                VALUES (?)
            ");
            $stmt->execute([$donation_id]);
            $conversation_id = $this->conn->lastInsertId();
            
            // Add participants
            $stmt = $this->conn->prepare("
                INSERT INTO chat_participants (conversation_id, user_id)
                VALUES (?, ?)
            ");
            
            foreach ($participants as $user_id) {
                $stmt->execute([$conversation_id, $user_id]);
            }
            
            $this->conn->commit();
            return $conversation_id;
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
    
    public function sendMessage($conversation_id, $sender_id, $message) {
        $stmt = $this->conn->prepare("
            INSERT INTO chat_messages (conversation_id, sender_id, message)
            VALUES (?, ?, ?)
        ");
        
        if ($stmt->execute([$conversation_id, $sender_id, $message])) {
            // Notify other participants
            $this->notifyParticipants($conversation_id, $sender_id);
            return true;
        }
        return false;
    }
    
    private function notifyParticipants($conversation_id, $sender_id) {
        // Get conversation participants except sender
        $stmt = $this->conn->prepare("
            SELECT user_id 
            FROM chat_participants 
            WHERE conversation_id = ? AND user_id != ?
        ");
        $stmt->execute([$conversation_id, $sender_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get sender name
        $stmt = $this->conn->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmt->execute([$sender_id]);
        $sender = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Create notification for each participant
        $notificationManager = new NotificationManager($this->conn);
        foreach ($participants as $participant_id) {
            $notificationManager->createNotification(
                $participant_id,
                "New message from " . $sender['username'],
                "You have a new message in your donation conversation",
                'message',
                $conversation_id
            );
        }
    }
    
    public function getMessages($conversation_id, $limit = 50) {
        $stmt = $this->conn->prepare("
            SELECT m.*, u.username as sender_name
            FROM chat_messages m
            JOIN users u ON m.sender_id = u.user_id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$conversation_id, $limit]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    public function getConversation($conversation_id) {
        $stmt = $this->conn->prepare("
            SELECT c.*, d.*, 
                   r.requester_id,
                   dr.donor_id,
                   u1.username as requester_name,
                   u2.username as donor_name
            FROM chat_conversations c
            JOIN material_donations d ON c.donation_id = d.donation_id
            JOIN material_donation_requests r ON d.request_id = r.request_id
            JOIN users u1 ON r.requester_id = u1.user_id
            JOIN users u2 ON d.donor_id = u2.user_id
            WHERE c.conversation_id = ?
        ");
        $stmt->execute([$conversation_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function markAsRead($conversation_id, $user_id) {
        $stmt = $this->conn->prepare("
            UPDATE chat_participants
            SET last_read_at = CURRENT_TIMESTAMP
            WHERE conversation_id = ? AND user_id = ?
        ");
        return $stmt->execute([$conversation_id, $user_id]);
    }
    
    public function getUnreadCount($user_id) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT m.conversation_id) as count
            FROM chat_messages m
            JOIN chat_participants p ON m.conversation_id = p.conversation_id
            WHERE p.user_id = ? 
            AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)
            AND m.sender_id != ?
        ");
        $stmt->execute([$user_id, $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    }
}