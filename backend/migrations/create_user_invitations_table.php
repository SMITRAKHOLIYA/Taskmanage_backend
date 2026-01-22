<?php
require_once __DIR__ . '/../config/db.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $sql = "CREATE TABLE IF NOT EXISTS user_invitations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        role VARCHAR(50) NOT NULL,
        token VARCHAR(255) NOT NULL UNIQUE,
        inviter_id INT NOT NULL,
        status ENUM('pending', 'accepted', 'expired') DEFAULT 'pending',
        company_id INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $db->exec($sql);
    echo "Table 'user_invitations' created successfully.\n";

} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
?>