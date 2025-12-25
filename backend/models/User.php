<?php
class User
{
    private $conn;
    private $table_name = "users";

    public $id;
    public $username;
    public $email;
    public $password;
    public $role;
    public $points;
    public $company_id;

    public $profile_pic;
    public $created_at;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create()
    {
        $query = "INSERT INTO " . $this->table_name . " SET username=:username, email=:email, password_hash=:password, role=:role, points=:points, company_id=:company_id, profile_pic=:profile_pic";
        $stmt = $this->conn->prepare($query);

        $this->username = htmlspecialchars(strip_tags($this->username));
        $this->email = htmlspecialchars(strip_tags($this->email));
        $this->password = htmlspecialchars(strip_tags($this->password));
        $this->role = htmlspecialchars(strip_tags($this->role));
        $this->points = isset($this->points) ? (int) $this->points : 0;
        $this->company_id = isset($this->company_id) ? (int) $this->company_id : null;

        $this->profile_pic = isset($this->profile_pic) ? htmlspecialchars(strip_tags($this->profile_pic)) : null;

        $stmt->bindParam(":username", $this->username);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":password", $this->password);
        $stmt->bindParam(":role", $this->role);
        $stmt->bindParam(":points", $this->points, PDO::PARAM_INT);
        $stmt->bindParam(":company_id", $this->company_id, PDO::PARAM_INT);

        $stmt->bindParam(":profile_pic", $this->profile_pic);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function emailExists()
    {
        $query = "SELECT id, username, password_hash, role, points, company_id, profile_pic, created_at FROM " . $this->table_name . " WHERE email = ? LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->email);
        $stmt->execute();
        $num = $stmt->rowCount();

        if ($num > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->id = $row['id'];
            $this->username = $row['username'];
            $this->password = $row['password_hash'];
            $this->role = $row['role'];
            $this->points = isset($row['points']) ? (int) $row['points'] : 0;
            $this->company_id = isset($row['company_id']) ? (int) $row['company_id'] : null;

            $this->profile_pic = $row['profile_pic'];
            $this->created_at = $row['created_at'];
            return true;
        }
        return false;
    }

    public function getById()
    {
        $query = "SELECT id, username, email, role, points, company_id, profile_pic, created_at FROM " . $this->table_name . " WHERE id = ? LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->username = $row['username'];
            $this->email = $row['email'];
            $this->role = $row['role'];
            $this->points = isset($row['points']) ? (int) $row['points'] : 0;
            $this->company_id = isset($row['company_id']) ? (int) $row['company_id'] : null;

            $this->profile_pic = $row['profile_pic'];
            $this->created_at = $row['created_at'];
            return true;
        }
        return false;
    }



    public function updateProfilePic($url)
    {
        $query = "UPDATE " . $this->table_name . " SET profile_pic = :profile_pic WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $this->profile_pic = htmlspecialchars(strip_tags($url));
        $stmt->bindParam(':profile_pic', $this->profile_pic);
        $stmt->bindParam(':id', $this->id);
        return $stmt->execute();
    }
}
?>