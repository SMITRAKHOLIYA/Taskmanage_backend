<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class SettingsController
{
    private $db;
    private $jwt;
    private $user_role;

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

        $this->user_id = $decoded->id; // Changed from user_role to user_id
        $this->user_role = $decoded->role; // Added to retain role information
        return true;
    }

    private function checkAdmin()
    {
        if ($this->user_role !== 'admin') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied. Only Admin can manage settings."));
            exit();
        }
    }

    public function getSettings()
    {
        $this->authenticate();
        // Allow all authenticated users to view company settings (e.g. for display on dashboard)

        $query = "SELECT * FROM settings LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            http_response_code(200);
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(array("message" => "Settings not found."));
        }
    }

    public function updateSettings()
    {
        $this->authenticate();
        $this->checkAdmin();

        $data = json_decode(file_get_contents("php://input"));

        // Check if settings exist
        $query = "SELECT id FROM settings LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            // Update
            $query = "UPDATE settings SET company_name=:name, company_address=:address, company_phone=:phone, company_email=:email";
        } else {
            // Insert
            $query = "INSERT INTO settings (company_name, company_address, company_phone, company_email) VALUES (:name, :address, :phone, :email)";
        }

        $stmt = $this->db->prepare($query);

        $name = htmlspecialchars(strip_tags($data->company_name));
        $address = htmlspecialchars(strip_tags($data->company_address));
        $phone = htmlspecialchars(strip_tags($data->company_phone));
        $email = htmlspecialchars(strip_tags($data->company_email));

        $stmt->bindParam(":name", $name);
        $stmt->bindParam(":address", $address);
        $stmt->bindParam(":phone", $phone);
        $stmt->bindParam(":email", $email);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(array("message" => "Settings updated."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to update settings."));
        }
    }
}
?>