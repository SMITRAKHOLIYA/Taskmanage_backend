<?php
$host = "localhost";
$db_name = "icei_40739181_task_manage_db";
$username = "icei_40739181";
$password = "smit20044";

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name, $username, $password);
    echo "Connected successfully to localhost!";
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>