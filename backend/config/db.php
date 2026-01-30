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
            // MySQL Connection
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            error_log("DB Connection Success: MySQL connected to " . $this->host);

        } catch (PDOException $exception) {
            error_log("DB Connection Failed (MySQL): " . $exception->getMessage());
            // Throw the exception so it can be caught by api.php
            throw $exception;
        }

        return $this->conn;
    }
}
?>