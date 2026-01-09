<?php
require_once __DIR__ . '/../config/db.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    // Add columns to customers table
    $alterCustomers = "ALTER TABLE customers 
        ADD COLUMN personal_bank_account_name VARCHAR(255) AFTER pan_card_number,
        ADD COLUMN personal_bank_name VARCHAR(100) AFTER personal_bank_account_name,
        ADD COLUMN personal_ifsc_code VARCHAR(20) AFTER personal_bank_name,
        ADD COLUMN personal_bank_id VARCHAR(50) AFTER personal_ifsc_code";

    try {
        $db->exec($alterCustomers);
        echo "Added personal banking columns to 'customers' table.\n";
    } catch (PDOException $e) {
        // Ignore if error contains "Duplicate column name"
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Columns already exist in 'customers' table.\n";
        } else {
            throw $e;
        }
    }

    // Add columns to group_persons table
    $alterGroupPersons = "ALTER TABLE group_persons 
        ADD COLUMN personal_bank_account_name VARCHAR(255) AFTER pan_card_number,
        ADD COLUMN personal_bank_name VARCHAR(100) AFTER personal_bank_account_name,
        ADD COLUMN personal_ifsc_code VARCHAR(20) AFTER personal_bank_name,
        ADD COLUMN personal_bank_id VARCHAR(50) AFTER personal_ifsc_code";

    try {
        $db->exec($alterGroupPersons);
        echo "Added personal banking columns to 'group_persons' table.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Columns already exist in 'group_persons' table.\n";
        } else {
            throw $e;
        }
    }

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage() . "\n");
}
?>