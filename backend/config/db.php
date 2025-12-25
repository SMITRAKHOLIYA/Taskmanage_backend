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
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $exception) {
            // Throw the exception so it can be caught by api.php
            throw $exception;
        }

        return $this->conn;
    }
}
?>