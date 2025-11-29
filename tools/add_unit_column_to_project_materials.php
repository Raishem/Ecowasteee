<?php
/**
 * Migration helper: ensure `unit` column exists on `project_materials`.
 *
 * Run from CLI: php tools\add_unit_column_to_project_materials.php
 * Or visit the file in browser while running a local dev server.
 */
require_once __DIR__ . '/../config.php';
try {
    $conn = getDBConnection();
    if (!$conn) throw new Exception('DB connect failed');

    // ensure unit exists
    $res = $conn->query("SHOW COLUMNS FROM project_materials LIKE 'unit'");
    if ($res && $res->num_rows > 0) {
        echo "Column 'unit' already exists.\n";
    } else {
        // Add column with a sensible default; allow NULL to be safe for older rows
        $sql = "ALTER TABLE project_materials ADD COLUMN unit VARCHAR(64) DEFAULT NULL";
        if ($conn->query($sql) === TRUE) {
            echo "Added column 'unit' to project_materials.\n";
            $conn->query("UPDATE project_materials SET unit = '' WHERE unit IS NULL");
        } else {
            throw new Exception('ALTER TABLE unit failed: ' . $conn->error);
        }
    }
    // next ensure created_at exists
    $res2 = $conn->query("SHOW COLUMNS FROM project_materials LIKE 'created_at'");
    if ($res2 && $res2->num_rows > 0) {
        echo "Column 'created_at' already exists.\n";
        exit(0);
    }

    // Add created_at as a DATETIME with default current timestamp to make future inserts safe
    $sql2 = "ALTER TABLE project_materials ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP";
    if ($conn->query($sql2) === TRUE) {
        echo "Added column 'created_at' to project_materials.\n";
        exit(0);
    } else {
        throw new Exception('ALTER TABLE created_at failed: ' . $conn->error);
    }

} catch (Exception $e) {
    echo 'Migration failed: ' . $e->getMessage() . "\n";
    exit(1);
}
