<?php
// Redundant CORS headers removed as they are handled in api.php


// require_once 'config.php';

class Database
{
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct()
    {
        // Local Development Settings
        $this->host = "sql108.iceiy.com";
        $this->db_name = "icei_40739181_task_manage_db";
        $this->username = "icei_40739181";
        $this->password = "smit20044";
    }

    public function getConnection()
    {
        $this->conn = null;

        try {
            // SQLite Connection
            // Ensure the directory is writable. Database file created by setup_sqlite.php
            // db.php is in /config, so we go up one level to /backend
            $dbFile = __DIR__ . '/../database.sqlite';
            $this->conn = new PDO("sqlite:" . $dbFile);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Allow larger timeouts if needed, though less relevant for SQLite
            $this->conn->setAttribute(PDO::ATTR_TIMEOUT, 10);

        } catch (PDOException $exception) {
            // Throw the exception so it can be caught by api.php
            throw $exception;
        }

        return $this->conn;
    }
}
?>