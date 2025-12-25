<?php
class ActivityLog
{
    private $conn;
    private $table_name = "activity_logs";

    public $id;
    public $user_id;
    public $task_id;
    public $action;
    public $details;
    public $created_at;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create($user_id, $action, $details, $task_id = null)
    {
        $query = "INSERT INTO " . $this->table_name . " SET user_id=:user_id, task_id=:task_id, action=:action, details=:details";
        $stmt = $this->conn->prepare($query);

        $action = htmlspecialchars(strip_tags($action));
        $details = htmlspecialchars(strip_tags($details));

        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":task_id", $task_id);
        $stmt->bindParam(":action", $action);
        $stmt->bindParam(":details", $details);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function getByUserId($user_id)
    {
        $query = "SELECT a.*, t.title as task_title 
                  FROM " . $this->table_name . " a 
                  LEFT JOIN tasks t ON a.task_id = t.id 
                  WHERE a.user_id = ? 
                  ORDER BY a.created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $user_id);
        $stmt->execute();
        return $stmt;
    }

    public function getAll($company_id = null)
    {
        $query = "SELECT a.*, t.title as task_title, u.username, u.profile_pic
                  FROM " . $this->table_name . " a 
                  LEFT JOIN tasks t ON a.task_id = t.id 
                  LEFT JOIN users u ON a.user_id = u.id";

        if ($company_id) {
            $query .= " WHERE u.company_id = :company_id";
        }

        $query .= " ORDER BY a.created_at DESC LIMIT 100";

        $stmt = $this->conn->prepare($query);

        if ($company_id) {
            $stmt->bindParam(":company_id", $company_id);
        }

        $stmt->execute();
        return $stmt;
    }
}
?>