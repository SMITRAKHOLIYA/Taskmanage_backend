<?php
class TaskAnswer
{
    private $conn;
    private $table_name = "task_answers";

    public $id;
    public $task_id;
    public $question_id;
    public $user_id;
    public $answer_type;
    public $answer_value;
    public $created_at;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create()
    {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET task_id=:task_id, question_id=:question_id, user_id=:user_id, 
                      answer_type=:answer_type, answer_value=:answer_value";

        $stmt = $this->conn->prepare($query);

        $this->task_id = htmlspecialchars(strip_tags($this->task_id));
        $this->question_id = htmlspecialchars(strip_tags($this->question_id));
        $this->user_id = htmlspecialchars(strip_tags($this->user_id));
        $this->answer_type = htmlspecialchars(strip_tags($this->answer_type));
        // answer_value might be JSON or text, so be careful with stripping tags if it's rich text
        // For now, let's strip tags to be safe, but maybe allow some if needed later
        $this->answer_value = htmlspecialchars(strip_tags($this->answer_value));

        $stmt->bindParam(":task_id", $this->task_id);
        $stmt->bindParam(":question_id", $this->question_id);
        $stmt->bindParam(":user_id", $this->user_id);
        $stmt->bindParam(":answer_type", $this->answer_type);
        $stmt->bindParam(":answer_value", $this->answer_value);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function getByTask($taskId)
    {
        $query = "SELECT ta.*, u.username, u.profile_pic 
                  FROM " . $this->table_name . " ta
                  JOIN users u ON ta.user_id = u.id
                  WHERE ta.task_id = :task_id
                  ORDER BY ta.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":task_id", $taskId);
        $stmt->execute();

        return $stmt;
    }

    // Check if an answer exists for a specific question and user (for updating)
    public function checkExisting($taskId, $questionId, $userId)
    {
        $query = "SELECT id FROM " . $this->table_name . " 
                  WHERE task_id = :task_id AND question_id = :question_id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":task_id", $taskId);
        $stmt->bindParam(":question_id", $questionId);
        $stmt->bindParam(":user_id", $userId);
        $stmt->execute();
        return $stmt;
    }

    public function update()
    {
        $query = "UPDATE " . $this->table_name . "
                  SET answer_value = :answer_value, answer_type = :answer_type
                  WHERE task_id = :task_id AND question_id = :question_id AND user_id = :user_id";

        $stmt = $this->conn->prepare($query);

        $this->answer_value = htmlspecialchars(strip_tags($this->answer_value));
        $this->answer_type = htmlspecialchars(strip_tags($this->answer_type));

        $stmt->bindParam(":answer_value", $this->answer_value);
        $stmt->bindParam(":answer_type", $this->answer_type);
        $stmt->bindParam(":task_id", $this->task_id);
        $stmt->bindParam(":question_id", $this->question_id);
        $stmt->bindParam(":user_id", $this->user_id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
}
?>