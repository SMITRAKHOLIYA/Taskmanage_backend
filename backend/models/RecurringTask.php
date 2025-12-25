<?php
class RecurringTask
{
    private $conn;
    private $table_name = "recurring_tasks";

    public $id;
    public $title;
    public $description;
    public $priority;
    public $assigned_to;
    public $project_id;
    public $frequency;
    public $start_date;
    public $next_run_date;
    public $created_by;
    public $created_at;
    public $updated_at;
    public $recurrence_trigger;
    public $questions;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create()
    {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET title=:title, description=:description, priority=:priority, 
                      assigned_to=:assigned_to, project_id=:project_id, 
                      frequency=:frequency, start_date=:start_date, next_run_date=:next_run_date, 
 
                      created_by=:created_by, recurrence_trigger=:recurrence_trigger, questions=:questions";

        $stmt = $this->conn->prepare($query);

        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->priority = htmlspecialchars(strip_tags($this->priority));
        $this->assigned_to = htmlspecialchars(strip_tags($this->assigned_to));
        $this->project_id = !empty($this->project_id) ? htmlspecialchars(strip_tags($this->project_id)) : null;
        $this->frequency = htmlspecialchars(strip_tags($this->frequency));
        $this->start_date = htmlspecialchars(strip_tags($this->start_date));
        $this->next_run_date = htmlspecialchars(strip_tags($this->next_run_date));
        $this->created_by = htmlspecialchars(strip_tags($this->created_by));
        $this->recurrence_trigger = htmlspecialchars(strip_tags($this->recurrence_trigger));
        // questions is JSON, so we don't strip tags, but maybe validate it's valid JSON?
        // For now, assume it's passed as a JSON string or array
        if (is_array($this->questions) || is_object($this->questions)) {
            $this->questions = json_encode($this->questions);
        }

        $stmt->bindParam(":title", $this->title);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":priority", $this->priority);
        $stmt->bindParam(":assigned_to", $this->assigned_to);
        $stmt->bindParam(":project_id", $this->project_id);
        $stmt->bindParam(":frequency", $this->frequency);
        $stmt->bindParam(":start_date", $this->start_date);
        $stmt->bindParam(":next_run_date", $this->next_run_date);
        $stmt->bindParam(":created_by", $this->created_by);
        $stmt->bindParam(":recurrence_trigger", $this->recurrence_trigger);
        $stmt->bindParam(":questions", $this->questions);

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    public function getOne()
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = ? LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->title = $row['title'];
            $this->description = $row['description'];
            $this->priority = $row['priority'];
            $this->assigned_to = $row['assigned_to'];
            $this->project_id = $row['project_id'];
            $this->frequency = $row['frequency'];
            $this->start_date = $row['start_date'];
            $this->next_run_date = $row['next_run_date'];
            $this->created_by = $row['created_by'];
            $this->created_at = $row['created_at'];
            $this->updated_at = $row['updated_at'];
            $this->recurrence_trigger = $row['recurrence_trigger'];
            $this->questions = $row['questions'];
            return true;
        }
        return false;
    }

    public function getAll()
    {
        $query = "SELECT * FROM " . $this->table_name . " ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function getDueTasks()
    {
        // Only get tasks that are scheduled based on date, AND trigger is 'schedule'
        $query = "SELECT * FROM " . $this->table_name . " WHERE next_run_date <= CURDATE() AND recurrence_trigger = 'schedule'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function updateNextRunDate($id, $frequency, $currentRunDate)
    {
        $nextDate = new DateTime($currentRunDate);
        if ($frequency === 'daily') {
            $nextDate->modify('+1 day');
        } elseif ($frequency === 'weekly') {
            $nextDate->modify('+1 week');
        } elseif ($frequency === 'monthly') {
            $nextDate->modify('+1 month');
        }

        $query = "UPDATE " . $this->table_name . " SET next_run_date = :next_run_date WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $newDateStr = $nextDate->format('Y-m-d');
        $stmt->bindParam(':next_run_date', $newDateStr);
        $stmt->bindParam(':id', $id);

        return $stmt->execute();
    }
}
?>