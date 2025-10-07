<?php
require_once __DIR__ . '/../config.php';

try {
    $conn = getDBConnection();
    
    // Create notifications table
    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        project_id INT,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id),
        FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
    )");
    
    // Create notification types lookup table
    $conn->query("CREATE TABLE IF NOT EXISTS notification_types (
        type_id INT AUTO_INCREMENT PRIMARY KEY,
        type_name VARCHAR(50) UNIQUE NOT NULL,
        icon_class VARCHAR(50),
        color_class VARCHAR(50)
    )");
    
    // Insert default notification types
    $default_types = [
        ['stage_complete', 'fa-flag-checkered', 'success'],
        ['material_added', 'fa-plus-circle', 'info'],
        ['material_updated', 'fa-sync', 'info'],
        ['project_updated', 'fa-edit', 'primary'],
        ['project_shared', 'fa-share', 'info'],
        ['material_needed', 'fa-exclamation-circle', 'warning']
    ];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO notification_types (type_name, icon_class, color_class) VALUES (?, ?, ?)");
    foreach ($default_types as $type) {
        $stmt->bind_param("sss", $type[0], $type[1], $type[2]);
        $stmt->execute();
    }
    
    echo "Notifications tables created successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>