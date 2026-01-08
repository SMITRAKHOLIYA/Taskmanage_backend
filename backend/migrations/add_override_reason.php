<?php
include_once __DIR__ . '/../config/db.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Check if column exists
    $checkQuery = "SHOW COLUMNS FROM tasks LIKE 'last_override_reason'";
    $stmt = $db->prepare($checkQuery);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        $query = "ALTER TABLE tasks ADD COLUMN last_override_reason TEXT DEFAULT NULL";
        $db->exec($query);
        echo "Column 'last_override_reason' added successfully.\n";
    } else {
        echo "Column 'last_override_reason' already exists.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>