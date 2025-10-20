<?php
require_once __DIR__ . '/../config.php';

try {
    $conn = getDBConnection();
    
    // Create project_stages table
    $conn->query("CREATE TABLE IF NOT EXISTS project_stages (
        stage_id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT,
        stage_number INT NOT NULL,
        stage_name VARCHAR(100) NOT NULL,
        description TEXT,
        is_completed BOOLEAN DEFAULT FALSE,
        completed_at DATETIME,
        FOREIGN KEY (project_id) REFERENCES projects(project_id)
    )");
    
    // Create default stage templates
    $conn->query("CREATE TABLE IF NOT EXISTS stage_templates (
        template_id INT AUTO_INCREMENT PRIMARY KEY,
        stage_number INT NOT NULL,
        stage_name VARCHAR(100) NOT NULL,
        description TEXT
    )");
    
    // Insert default stages if they don't exist
    // Default stages: Planning removed. Start with Material Collection.
    $default_stages = [
        [1, 'Material Collection', 'Gather all required materials for your project.'],
        [2, 'Preparation', 'Clean and prepare your materials for recycling.'],
        [3, 'Construction', 'Start building your recycled creation.'],
        [4, 'Finishing', 'Add final touches and complete your project.'],
        [5, 'Documentation', 'Document your completed project with photos and descriptions.']
    ];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO stage_templates (stage_number, stage_name, description) VALUES (?, ?, ?)");
    foreach ($default_stages as $stage) {
        $stmt->bind_param("iss", $stage[0], $stage[1], $stage[2]);
        $stmt->execute();
    }
    
    echo "Project stages tables created successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>