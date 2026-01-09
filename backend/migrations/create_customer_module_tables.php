<?php
require_once __DIR__ . '/../config/db.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    // 1. Customers Table
    $sql = "CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        whatsapp_number VARCHAR(20),
        pan_card_number VARCHAR(20),
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->exec($sql);
    echo "Table 'customers' created successfully.\n";

    // 2. Customer Groups Table
    $sql = "CREATE TABLE IF NOT EXISTS customer_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->exec($sql);
    echo "Table 'customer_groups' created successfully.\n";

    // 3. Group Persons Table
    $sql = "CREATE TABLE IF NOT EXISTS group_persons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        whatsapp_number VARCHAR(20),
        pan_card_number VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id) REFERENCES customer_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->exec($sql);
    echo "Table 'group_persons' created successfully.\n";

    // 4. Linked Companies Table (Polymorphic)
    $sql = "CREATE TABLE IF NOT EXISTS linked_companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_type ENUM('customer', 'group_person') NOT NULL,
        owner_id INT NOT NULL,
        company_name VARCHAR(255) NOT NULL,
        gst_number VARCHAR(50),
        pan_number VARCHAR(20),
        bank_account_name VARCHAR(255),
        bank_name VARCHAR(100),
        ifsc_code VARCHAR(20),
        bank_id VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (owner_type, owner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->exec($sql);
    echo "Table 'linked_companies' created successfully.\n";

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage() . "\n");
}
?>
