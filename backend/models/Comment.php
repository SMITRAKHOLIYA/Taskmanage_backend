<?php
class Comment
{
    private $conn;
    private $table = 'comments';

    public $id;
    public $task_id;
    public $user_id;
    public $content;
    public $context_id;
    public $created_at;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create()
    {
        $query = "INSERT INTO " . $this->table . " (task_id, user_id, content, context_id) VALUES (:task_id, :user_id, :content, :context_id)";
        $stmt = $this->conn->prepare($query);

        $this->content = htmlspecialchars(strip_tags($this->content));
        $this->context_id = !empty($this->context_id) ? htmlspecialchars(strip_tags($this->context_id)) : null;

        $stmt->bindParam(':task_id', $this->task_id);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':content', $this->content);
        $stmt->bindParam(':context_id', $this->context_id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function getByTask($taskId)
    {
        $query = "SELECT c.*, u.username, u.profile_pic 
                  FROM " . $this->table . " c
                  JOIN users u ON c.user_id = u.id
                  WHERE c.task_id = :task_id
                  ORDER BY c.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':task_id', $taskId);
        $stmt->execute();

        return $stmt;
    }
}
?>