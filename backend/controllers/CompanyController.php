<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class CompanyController
{
    private $db;
    private $jwt;
    private $user_role;
    private $user_id;
    private $company_id;

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
        $this->company_id = isset($decoded->company_id) ? $decoded->company_id : null;
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

    public function create() // Create a new company
    {
        $this->authenticate();
        $this->checkOwner();

        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->name)) {
            $query = "INSERT INTO companies (name, industry, status) VALUES (:name, :industry, :status)";
            $stmt = $this->db->prepare($query);

            $name = htmlspecialchars(strip_tags($data->name));
            $industry = isset($data->industry) ? htmlspecialchars(strip_tags($data->industry)) : NULL;
            $status = isset($data->status) ? htmlspecialchars(strip_tags($data->status)) : 'active';

            $stmt->bindParam(":name", $name);
            $stmt->bindParam(":industry", $industry);
            $stmt->bindParam(":status", $status);

            if ($stmt->execute()) {
                $companyId = $this->db->lastInsertId();
                http_response_code(201);
                echo json_encode(array("message" => "Company created.", "id" => $companyId));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to create company."));
            }
        } else {
            http_response_code(400);
            echo json_encode(array("message" => "Incomplete data. Name is required."));
        }
    }

    public function getOne($id)
    {
        $this->authenticate();
        $query = "SELECT * FROM companies WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            http_response_code(200);
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Company not found"]);
        }
    }

    public function getAll() // Get all companies (Filtered by Owner's company)
    {
        $this->authenticate();
        $this->checkOwner();

        // Filter by the logged-in owner's company_id
        if ($this->company_id) {
            $query = "SELECT * FROM companies WHERE id = :company_id ORDER BY created_at DESC";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(":company_id", $this->company_id);
            $stmt->execute();
        } else {
            // Fallback if no company_id (shouldn't happen for valid owner, but safe default empty)
            echo json_encode([]);
            return;
        }

        $companies_arr = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            array_push($companies_arr, $row);
        }

        http_response_code(200);
        echo json_encode($companies_arr);
    }

    public function update($id)
    {
        $this->authenticate();
        $this->checkOwner();

        $data = json_decode(file_get_contents("php://input"));

        $query = "UPDATE companies SET name = :name, industry = :industry, status = :status WHERE id = :id";
        $stmt = $this->db->prepare($query);

        $name = htmlspecialchars(strip_tags($data->name));
        $industry = isset($data->industry) ? htmlspecialchars(strip_tags($data->industry)) : "";
        $status = isset($data->status) ? htmlspecialchars(strip_tags($data->status)) : 'active';

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':industry', $industry);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(array("message" => "Company updated."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to update company."));
        }
    }

    public function delete($id)
    {
        $this->authenticate();
        $this->checkOwner();

        $query = "DELETE FROM companies WHERE id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(1, $id);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(array("message" => "Company deleted."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to delete company."));
        }
    }
}
?>