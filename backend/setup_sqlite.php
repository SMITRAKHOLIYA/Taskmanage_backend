<?php
// Initialize SQLite Database
$dbFile = __DIR__ . '/database.sqlite';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create Users Table
    $query = "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(50) DEFAULT 'user',
        points INTEGER DEFAULT 0,
        company_id INTEGER NULL,
        profile_pic VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        reset_token_hash VARCHAR(255) NULL,
        reset_token_expires_at DATETIME NULL,
        last_reset_request_at DATETIME NULL
    )";
    $db->exec($query);

    // Create Companies Table (Minimal for FK placeholder)
    $queryCompanies = "CREATE TABLE IF NOT EXISTS companies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(255) NOT NULL,
        company_size VARCHAR(50),
        industry VARCHAR(100),
        status VARCHAR(50) DEFAULT 'active'
    )";
    $db->exec($queryCompanies);

    // Insert Dummy Company
    $db->exec("INSERT OR IGNORE INTO companies (id, name) VALUES (1, 'Demo Company')");

    // Insert Dummy User if not exists
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = 'test@example.com'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $passHash = password_hash('password123', PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (username, email, password_hash, role, company_id, created_at) 
                VALUES ('testuser', 'test@example.com', '$passHash', 'user', 1, datetime('now'))";
        $db->exec($sql);
        echo "Created test user: test@example.com / password123\n";
    }

    echo "SQLite database initialized successfully at $dbFile\n";

    // Set permissions
    chmod($dbFile, 0777);

} catch (PDOException $e) {
    die("DB Init Failed: " . $e->getMessage());
}
?>