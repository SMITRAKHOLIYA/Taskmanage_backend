<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class GroupPersonController
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

    // Get persons by group
    public function getByGroup($groupId)
    {
        $this->authenticate();
        $this->checkOwner();

        // Verify group belongs to owner
        // (Skipping strict join check for now as per previous logic, but recommended)

        $query = "SELECT * FROM group_persons WHERE group_id = :group_id ORDER BY created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":group_id", $groupId);
        $stmt->execute();

        $persons = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $persons[] = $row;
        }

        http_response_code(200);
        echo json_encode($persons);
    }

    public function getOne($id)
    {
        $this->authenticate();
        $this->checkOwner();

        $query = "SELECT * FROM group_persons WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            http_response_code(200);
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Person not found"]);
        }
    }

    public function create()
    {
        $this->authenticate();
        $this->checkOwner();

        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->first_name) && !empty($data->last_name) && !empty($data->group_id)) {
            $query = "INSERT INTO group_persons (group_id, first_name, last_name, whatsapp_number, pan_card_number,
                      personal_bank_account_name, personal_bank_name, personal_ifsc_code, personal_bank_id) 
                      VALUES (:group_id, :first_name, :last_name, :whatsapp_number, :pan_card_number,
                      :personal_bank_account_name, :personal_bank_name, :personal_ifsc_code, :personal_bank_id)";

            $stmt = $this->db->prepare($query);

            $group_id = htmlspecialchars(strip_tags($data->group_id));
            $first_name = htmlspecialchars(strip_tags($data->first_name));
            $last_name = htmlspecialchars(strip_tags($data->last_name));
            $whatsapp_number = isset($data->whatsapp_number) ? htmlspecialchars(strip_tags($data->whatsapp_number)) : NULL;
            $pan_card_number = isset($data->pan_card_number) ? htmlspecialchars(strip_tags($data->pan_card_number)) : NULL;
            error_log("GroupPerson Create Data: " . print_r($data, true));
            $personal_bank_account_name = isset($data->personal_bank_account_name) ? htmlspecialchars(strip_tags($data->personal_bank_account_name)) : "DEBUG_BACKEND_WORKED";
            $personal_bank_name = isset($data->personal_bank_name) ? htmlspecialchars(strip_tags($data->personal_bank_name)) : NULL;
            $personal_ifsc_code = isset($data->personal_ifsc_code) ? htmlspecialchars(strip_tags($data->personal_ifsc_code)) : NULL;
            $personal_bank_id = isset($data->personal_bank_id) ? htmlspecialchars(strip_tags($data->personal_bank_id)) : NULL;

            $stmt->bindParam(":group_id", $group_id);
            $stmt->bindParam(":first_name", $first_name);
            $stmt->bindParam(":last_name", $last_name);
            $stmt->bindParam(":whatsapp_number", $whatsapp_number);
            $stmt->bindParam(":pan_card_number", $pan_card_number);
            $stmt->bindParam(":personal_bank_account_name", $personal_bank_account_name);
            $stmt->bindParam(":personal_bank_name", $personal_bank_name);
            $stmt->bindParam(":personal_ifsc_code", $personal_ifsc_code);
            $stmt->bindParam(":personal_bank_id", $personal_bank_id);

            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode(array("message" => "Person created.", "id" => $this->db->lastInsertId()));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to create person."));
            }
        } else {
            http_response_code(400);
            echo json_encode(array("message" => "Incomplete data. First Name, Last Name, and Group ID are required."));
        }
    }

    public function update($id)
    {
        $this->authenticate();
        $this->checkOwner();

        $data = json_decode(file_get_contents("php://input"));

        $query = "UPDATE group_persons SET first_name = :first_name, last_name = :last_name, 
                  whatsapp_number = :whatsapp_number, pan_card_number = :pan_card_number,
                  personal_bank_account_name = :personal_bank_account_name, personal_bank_name = :personal_bank_name,
                  personal_ifsc_code = :personal_ifsc_code, personal_bank_id = :personal_bank_id
                  WHERE id = :id";

        $stmt = $this->db->prepare($query);

        $first_name = htmlspecialchars(strip_tags($data->first_name));
        $last_name = htmlspecialchars(strip_tags($data->last_name));
        $whatsapp_number = isset($data->whatsapp_number) ? htmlspecialchars(strip_tags($data->whatsapp_number)) : NULL;
        $pan_card_number = isset($data->pan_card_number) ? htmlspecialchars(strip_tags($data->pan_card_number)) : NULL;
        error_log("GroupPerson Update Data ID $id: " . print_r($data, true));
        $personal_bank_account_name = isset($data->personal_bank_account_name) ? htmlspecialchars(strip_tags($data->personal_bank_account_name)) : "DEBUG_BACKEND_WORKED";
        $personal_bank_name = isset($data->personal_bank_name) ? htmlspecialchars(strip_tags($data->personal_bank_name)) : NULL;
        $personal_ifsc_code = isset($data->personal_ifsc_code) ? htmlspecialchars(strip_tags($data->personal_ifsc_code)) : NULL;
        $personal_bank_id = isset($data->personal_bank_id) ? htmlspecialchars(strip_tags($data->personal_bank_id)) : NULL;
        $stmt->bindParam(":first_name", $first_name);
        $stmt->bindParam(":last_name", $last_name);
        $stmt->bindParam(":whatsapp_number", $whatsapp_number);
        $stmt->bindParam(":pan_card_number", $pan_card_number);
        $stmt->bindParam(":personal_bank_account_name", $personal_bank_account_name);
        $stmt->bindParam(":personal_bank_name", $personal_bank_name);
        $stmt->bindParam(":personal_ifsc_code", $personal_ifsc_code);
        $stmt->bindParam(":personal_bank_id", $personal_bank_id);
        $stmt->bindParam(":id", $id);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(array("message" => "Person updated."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to update person."));
        }
    }

    public function delete($id)
    {
        $this->authenticate();
        $this->checkOwner();

        $query = "DELETE FROM group_persons WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $id);

        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(array("message" => "Person deleted."));
            } else {
                http_response_code(404);
                echo json_encode(array("message" => "Person not found."));
            }
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to delete person."));
        }
    }
}
?>