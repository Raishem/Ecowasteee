<?php
require_once __DIR__ . '/../config.php';

try {
    $conn = getDBConnection();

    echo "Starting migration: remove 'Planning' stage templates and update project statuses...\n";

    // Remove any stage templates named 'Planning' (case-insensitive)
    $sql = "DELETE FROM stage_templates WHERE LOWER(stage_name) = 'planning'";
    if ($conn->query($sql) === false) {
        throw new Exception('Error deleting planning templates: ' . $conn->error);
    }
    echo "Removed planning templates (if any). Affected rows: " . $conn->affected_rows . "\n";

    // Update projects that still list 'planning' as their status
    $sql = "UPDATE projects SET status = 'collecting' WHERE LOWER(IFNULL(status,'')) = 'planning'";
    if ($conn->query($sql) === false) {
        throw new Exception('Error updating project statuses: ' . $conn->error);
    }
    echo "Updated project statuses from 'planning' to 'collecting'. Affected rows: " . $conn->affected_rows . "\n";

    echo "Migration finished. You may re-run tools/create_project_stages.php to ensure default templates are present.\n";

} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}

?>
