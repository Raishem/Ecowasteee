<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
    $conn = getDBConnection();
    if (!$conn) throw new Exception('DB connection failed');

    $sqls = [
        "CREATE TABLE IF NOT EXISTS shared_projects (
            shared_id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NULL,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            cover_photo VARCHAR(255) DEFAULT NULL,
            tags VARCHAR(255) DEFAULT NULL,
            privacy ENUM('public','private') DEFAULT 'public',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS shared_materials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shared_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            quantity VARCHAR(64) DEFAULT NULL,
            extra JSON DEFAULT NULL,
            FOREIGN KEY (shared_id) REFERENCES shared_projects(shared_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS shared_steps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shared_id INT NOT NULL,
            step_number INT NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            instructions TEXT,
            is_done TINYINT(1) DEFAULT 0,
            FOREIGN KEY (shared_id) REFERENCES shared_projects(shared_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS shared_step_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            step_id INT NOT NULL,
            path VARCHAR(255) NOT NULL,
            FOREIGN KEY (step_id) REFERENCES shared_steps(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS shared_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shared_id INT NOT NULL,
            user_id INT NOT NULL,
            activity_type VARCHAR(64) NOT NULL,
            data JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (shared_id) REFERENCES shared_projects(shared_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS shared_likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shared_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY(shared_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS shared_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shared_id INT NOT NULL,
            user_id INT NOT NULL,
            comment TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($sqls as $sql) {
        // Use exec if PDO, otherwise try query for mysqli
        if (method_exists($conn, 'exec')) {
            $conn->exec($sql);
        } else {
            $conn->query($sql);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Shared tables created or exist']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
