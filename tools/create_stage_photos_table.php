<?php
require_once __DIR__ . '/../config.php';

try {
    $conn = getDBConnection();
    
    // Create stage_photos table
    $sql = "CREATE TABLE IF NOT EXISTS stage_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        stage_number INT NOT NULL,
        photo_path VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $conn->query($sql);
    echo "stage_photos table created successfully\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}