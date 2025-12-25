<?php
class Project
{
    private $conn;
    private $table_name = "projects";
    private $members_table = "project_members";

    public $id;
    public $title;
    public $description;
    public $status;
    public $created_by;
    public $created_at;
    public $updated_at;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create()
    {
        $query = "INSERT INTO " . $this->table_name . " SET title=:title, description=:description, status=:status, created_by=:created_by";
        $stmt = $this->conn->prepare($query);

        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->created_by = htmlspecialchars(strip_tags($this->created_by));

        $stmt->bindParam(":title", $this->title);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":status", $this->status);
        $stmt->bindParam(":created_by", $this->created_by);

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    public function getAll($user_id, $role, $company_id = null)
    {
        // Admin sees all, Owner sees company's, others see only projects they are members of or created
        $query = "SELECT p.*, u.username as creator_name 
                  FROM " . $this->table_name . " p
                  LEFT JOIN users u ON p.created_by = u.id";

        if ($role === 'owner' && $company_id) {
            $query .= " WHERE u.company_id = :company_id";
        } elseif ($role !== 'admin' && $role !== 'manager') {
            $query .= " JOIN " . $this->members_table . " pm ON p.id = pm.project_id 
                        WHERE pm.user_id = :user_id OR p.created_by = :user_id";
        }

        $query .= " ORDER BY p.created_at DESC";

        $stmt = $this->conn->prepare($query);

        if ($role === 'owner' && $company_id) {
            $stmt->bindParam(":company_id", $company_id);
        } elseif ($role !== 'admin' && $role !== 'manager') {
            $stmt->bindParam(":user_id", $user_id);
        }

        $stmt->execute();
        return $stmt;
    }

    public function getById()
    {
        $query = "SELECT p.*, u.username as creator_name 
                  FROM " . $this->table_name . " p
                  LEFT JOIN users u ON p.created_by = u.id
                  WHERE p.id = ? LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->title = $row['title'];
            $this->description = $row['description'];
            $this->status = $row['status'];
            $this->created_by = $row['created_by'];
            $this->created_at = $row['created_at'];
            $this->updated_at = $row['updated_at'];
            return $row;
        }
        return false;
    }

    public function update()
    {
        $query = "UPDATE " . $this->table_name . "
                  SET title = :title,
                      description = :description,
                      status = :status
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->id = htmlspecialchars(strip_tags($this->id));

        $stmt->bindParam(":title", $this->title);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":status", $this->status);
        $stmt->bindParam(":id", $this->id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function delete()
    {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $this->id = htmlspecialchars(strip_tags($this->id));
        $stmt->bindParam(1, $this->id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function addMember($user_id)
    {
        $query = "INSERT IGNORE INTO " . $this->members_table . " SET project_id=:project_id, user_id=:user_id";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":project_id", $this->id);
        $stmt->bindParam(":user_id", $user_id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function removeMember($user_id)
    {
        $query = "DELETE FROM " . $this->members_table . " WHERE project_id=:project_id AND user_id=:user_id";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":project_id", $this->id);
        $stmt->bindParam(":user_id", $user_id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function getMembers()
    {
        $query = "SELECT u.id, u.username, u.email, u.profile_pic 
                  FROM users u
                  JOIN " . $this->members_table . " pm ON u.id = pm.user_id
                  WHERE pm.project_id = :project_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":project_id", $this->id);
        $stmt->execute();
        return $stmt;
    }

    public function getProgress()
    {
        $query = "SELECT status, COUNT(*) as count FROM tasks WHERE project_id = :project_id AND deleted_at IS NULL GROUP BY status";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":project_id", $this->id);
        $stmt->execute();

        $total = 0;
        $completed = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $count = (int) $row['count'];
            $total += $count;
            if ($row['status'] === 'completed') {
                $completed += $count;
            }
        }

        return $total > 0 ? round(($completed / $total) * 100) : 0;
    }
}
?>