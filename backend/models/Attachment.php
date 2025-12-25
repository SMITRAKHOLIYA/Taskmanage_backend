<?php
class Attachment
{
    private $conn;
    private $table = 'attachments';

    public $id;
    public $task_id;
    public $user_id;
    public $file_name;
    public $file_path;
    public $created_at;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create()
    {
        $query = "INSERT INTO " . $this->table . " (task_id, user_id, file_name, file_path) VALUES (:task_id, :user_id, :file_name, :file_path)";
        $stmt = $this->conn->prepare($query);

        $this->file_name = htmlspecialchars(strip_tags($this->file_name));
        $this->file_path = htmlspecialchars(strip_tags($this->file_path));

        $stmt->bindParam(':task_id', $this->task_id);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':file_name', $this->file_name);
        $stmt->bindParam(':file_path', $this->file_path);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function getByTask($taskId)
    {
        $query = "SELECT a.*, u.username 
                  FROM " . $this->table . " a
                  JOIN users u ON a.user_id = u.id
                  WHERE a.task_id = :task_id
                  ORDER BY a.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':task_id', $taskId);
        $stmt->execute();

        return $stmt;
    }
}
?>