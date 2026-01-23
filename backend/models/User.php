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
    public $company_name; // New property

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create()
    {
        $query = "INSERT INTO " . $this->table_name . " (username, email, password_hash, role, points, company_id, profile_pic, created_at) VALUES (:username, :email, :password, :role, :points, :company_id, :profile_pic, :created_at)";
        $stmt = $this->conn->prepare($query);

        $this->username = htmlspecialchars(strip_tags($this->username));
        $this->email = htmlspecialchars(strip_tags($this->email));
        // Do NOT sanitize the hash, it's safe and necessary matching

        $this->role = htmlspecialchars(strip_tags($this->role));

        // Handle Nullable Integers - STRICT MODE
        $pointsVal = isset($this->points) ? (int) $this->points : 0;

        // STRICT: company_id must be provided.
        // For Owner creation during bootstrapping, the Controller logic must handle assignment updates.
        // But for all standard creation, this should be valid.
        $companyIdVal = isset($this->company_id) ? (int) $this->company_id : null;

        if ($companyIdVal === 0 || $companyIdVal === null) {
            // Allow NULL ONLY if explicitly intended (e.g. Owner bootstrapping)
            // But based on USER REQUEST, "No user exists without company_id".
            // However, PHP null is needed for the SQL "NULL" value if we are inserting null.
            $companyIdVal = null;
        }

        $this->profile_pic = isset($this->profile_pic) ? htmlspecialchars(strip_tags($this->profile_pic)) : null;
        $this->created_at = date('Y-m-d H:i:s');

        $stmt->bindParam(":username", $this->username);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":password", $this->password);
        $stmt->bindParam(":role", $this->role);
        $stmt->bindValue(":points", $pointsVal, PDO::PARAM_INT);
        $stmt->bindParam(":created_at", $this->created_at);

        if ($companyIdVal === null) {
            $stmt->bindValue(":company_id", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":company_id", $companyIdVal, PDO::PARAM_INT);
        }

        $stmt->bindValue(":profile_pic", $this->profile_pic);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function emailExists()
    {
        $query = "SELECT u.id, u.username, u.password_hash, u.role, u.points, u.company_id, u.profile_pic, u.created_at, c.name as company_name 
                  FROM " . $this->table_name . " u 
                  LEFT JOIN companies c ON u.company_id = c.id 
                  WHERE u.email = ? LIMIT 0,1";
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
            $this->company_name = isset($row['company_name']) ? $row['company_name'] : null;
            return true;
        }
        return false;
    }

    public function getById()
    {
        $query = "SELECT u.id, u.username, u.email, u.role, u.points, u.company_id, u.profile_pic, u.created_at, c.name as company_name 
                  FROM " . $this->table_name . " u 
                  LEFT JOIN companies c ON u.company_id = c.id 
                  WHERE u.id = ? LIMIT 0,1";
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
            $this->company_name = isset($row['company_name']) ? $row['company_name'] : null;
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

    // --- Forgot Password Methods ---
    // Methods for handling password reset functionality

    public function saveResetToken($tokenHash, $expiresAt)
    {
        $query = "UPDATE " . $this->table_name . " 
                  SET reset_token_hash = :hash, 
                      reset_token_expires_at = :expires, 
                      last_reset_request_at = NOW() 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':hash', $tokenHash);
        $stmt->bindParam(':expires', $expiresAt);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }

    public function getByResetToken($tokenHash)
    {
        $query = "SELECT id, reset_token_expires_at FROM " . $this->table_name . " 
                  WHERE reset_token_hash = :hash LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':hash', $tokenHash);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->id = $row['id'];
            // Check expiry
            if (strtotime($row['reset_token_expires_at']) > time()) {
                return true;
            }
        }
        return false;
    }

    public function updatePassword($newPasswordHash)
    {
        $query = "UPDATE " . $this->table_name . " 
                  SET password_hash = :password, 
                      reset_token_hash = NULL, 
                      reset_token_expires_at = NULL 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':password', $newPasswordHash);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }
}
?>