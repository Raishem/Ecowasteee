<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
    $conn = getDBConnection();
    if (!$conn) throw new Exception('DB connection failed');

    $sql = "CREATE TABLE IF NOT EXISTS project_step_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        step_id INT NOT NULL,
        is_done TINYINT(1) DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $conn->exec($sql);
    echo json_encode(['success' => true, 'message' => 'Table project_step_progress exists or was created']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
