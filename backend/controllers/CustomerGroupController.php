<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class CustomerGroupController
{
    private $db;
    private $jwt;
    private $user_role;
    private $user_id;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->jwt = new JWTHandler();
    }

    private function authenticate()
    {
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
        $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

        if (empty($authHeader) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (empty($authHeader) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (!$authHeader) {
            http_response_code(401);
            echo json_encode(array("message" => "No token provided."));
            exit();
        }

        $token = str_replace('Bearer ', '', $authHeader);
        $decoded = $this->jwt->validate_jwt($token);

        if (!$decoded) {
            http_response_code(401);
            echo json_encode(array("message" => "Invalid token."));
            exit();
        }

        $this->user_role = $decoded->role;
        $this->user_id = $decoded->id;
        return true;
    }

    private function checkOwner()
    {
        if ($this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied. Owner only."));
            exit();
        }
    }

    public function getAll()
    {
        $this->authenticate();
        $this->checkOwner();

        $query = "SELECT * FROM customer_groups WHERE created_by = :created_by ORDER BY created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":created_by", $this->user_id);
        $stmt->execute();

        $groups = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $groups[] = $row;
        }

        http_response_code(200);
        echo json_encode($groups);
    }

    public function getOne($id)
    {
        $this->authenticate();
        $this->checkOwner();

        $query = "SELECT * FROM customer_groups WHERE id = :id AND created_by = :created_by";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":created_by", $this->user_id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            http_response_code(200);
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Group not found"]);
        }
    }

    public function create()
    {
        $this->authenticate();
        $this->checkOwner();

        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->name)) {
            $query = "INSERT INTO customer_groups (name, created_by) VALUES (:name, :created_by)";
            $stmt = $this->db->prepare($query);

            $name = htmlspecialchars(strip_tags($data->name));
            $stmt->bindParam(":name", $name);
            $stmt->bindParam(":created_by", $this->user_id);

            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode(array("message" => "Group created.", "id" => $this->db->lastInsertId()));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to create group."));
            }
        } else {
            http_response_code(400);
            echo json_encode(array("message" => "Incomplete data. Group Name is required."));
        }
    }

    public function update($id)
    {
        $this->authenticate();
        $this->checkOwner();

        $data = json_decode(file_get_contents("php://input"));

        // Add check for existence/ownership
        $checkQuery = "SELECT id FROM customer_groups WHERE id = :id AND created_by = :created_by";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(":id", $id);
        $checkStmt->bindParam(":created_by", $this->user_id);
        $checkStmt->execute();
        if ($checkStmt->rowCount() == 0) {
            http_response_code(404);
            echo json_encode(["message" => "Group not found or access denied."]);
            return;
        }

        $query = "UPDATE customer_groups SET name = :name WHERE id = :id";
        $stmt = $this->db->prepare($query);

        $name = htmlspecialchars(strip_tags($data->name));
        $stmt->bindParam(":name", $name);
        $stmt->bindParam(":id", $id);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(array("message" => "Group updated."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to update group."));
        }
    }

    public function delete($id)
    {
        $this->authenticate();
        $this->checkOwner();

        $query = "DELETE FROM customer_groups WHERE id = :id AND created_by = :created_by";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":created_by", $this->user_id);

        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(array("message" => "Group deleted."));
            } else {
                http_response_code(404);
                echo json_encode(array("message" => "Group not found or access denied."));
            }
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to delete group."));
        }
    }
}
?>