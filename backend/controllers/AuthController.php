<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../models/User.php';
include_once __DIR__ . '/../utils/JWTHandler.php';
include_once __DIR__ . '/../models/ActivityLog.php';

class AuthController
{
    private $db;
    private $user;
    private $jwt;
    private $activityLog;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->user = new User($this->db);
        $this->jwt = new JWTHandler();
        $this->activityLog = new ActivityLog($this->db);
    }

    public function login()
    {
        $input = file_get_contents("php://input");
        $data = json_decode($input);

        // Handle both JSON and Form Data safely
        $email = ($data && isset($data->email)) ? $data->email : ($_POST['email'] ?? '');
        $password = ($data && isset($data->password)) ? $data->password : ($_POST['password'] ?? '');

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(["message" => "Email and password are required"]);
            return;
        }

        $this->user->email = $email;
        $email_exists = $this->user->emailExists();

        if (!$email_exists) {
            http_response_code(401);
            echo json_encode(["message" => "No account found with this email."]);
            return;
        }

        if (!password_verify($password, $this->user->password)) {
            http_response_code(401);
            echo json_encode(["message" => "Incorrect password."]);
            return;
        }

        // Login Successful
        $token_data = [
            "id" => $this->user->id,
            "username" => $this->user->username,
            "email" => $this->user->email,
            "role" => $this->user->role,
            "points" => $this->user->points,
            "company_id" => $this->user->company_id
        ];

        $token = $this->jwt->generate_jwt($token_data);

        // Log activity
        $this->activityLog->create($this->user->id, "User Login", "User logged in successfully");

        http_response_code(200);
        echo json_encode([
            "message" => "Successful login",
            "token" => $token,
            "user" => $token_data
        ]);
    }

    public function register()
    {
        $input = file_get_contents("php://input");
        $data = json_decode($input);

        // Handle both JSON and Form Data
        $username = ($data && isset($data->username)) ? $data->username : ($_POST['username'] ?? '');
        $email = ($data && isset($data->email)) ? $data->email : ($_POST['email'] ?? '');
        $password = ($data && isset($data->password)) ? $data->password : ($_POST['password'] ?? '');
        $role = ($data && isset($data->role)) ? $data->role : ($_POST['role'] ?? 'user');
        $company_id = ($data && isset($data->company_id)) ? $data->company_id : ($_POST['company_id'] ?? null);

        if (empty($username) || empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(["message" => "All fields are required"]);
            return;
        }

        $this->user->email = $email;
        if ($this->user->emailExists()) {
            http_response_code(400);
            echo json_encode(["message" => "Email already exists"]);
            return;
        }

        if ($role === 'owner') {
            $companyName = $data->company_name ?? $_POST['company_name'] ?? ''; // Note: Frontend uses 'companyName' or we need to align
            // Frontend sends 'companyName' in JSON? Let's check Signup.jsx again.
            // Signup.jsx: register(username, email, password, role, isOwner ? companyName : null);
            // AuthContext: sends { username, email, password, role, company_name: companyName } (Assumption or check context)

            // Wait, I need to check AuthContext to be sure of the key name. 
            // Assuming key is 'company_name' or I check $data structure.
            // Making it robust:
            // Making it robust:
            $companyName = ($data && isset($data->company_name)) ? $data->company_name :
                (($data && isset($data->companyName)) ? $data->companyName :
                    ($_POST['company_name'] ?? ''));

            $companySize = ($data && isset($data->company_size)) ? $data->company_size :
                (($data && isset($data->companySize)) ? $data->companySize :
                    ($_POST['company_size'] ?? NULL));

            $industry = ($data && isset($data->industry)) ? $data->industry : ($_POST['industry'] ?? NULL);

            if (empty($companyName)) {
                http_response_code(400);
                echo json_encode(["message" => "Company Name is required for Owners"]);
                return;
            }

            // Create Company
            $query = "INSERT INTO companies (name, company_size, industry, status) VALUES (:name, :company_size, :industry, 'active')";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(":name", $companyName);
            $stmt->bindParam(":company_size", $companySize);
            $stmt->bindParam(":industry", $industry);

            if ($stmt->execute()) {
                $company_id = $this->db->lastInsertId();
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create company"]);
                return;
            }
        }

        $this->user->username = $username;
        $this->user->password = password_hash($password, PASSWORD_DEFAULT);
        $this->user->role = $role;
        $this->user->company_id = $company_id;

        if ($this->user->create()) {
            http_response_code(201);
            echo json_encode(["message" => "User registered successfully", "user" => ["role" => $role, "id" => $this->user->id]]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Unable to register user"]);
        }
    }

    public function getCompanyDetails()
    {
        $id = isset($_GET['id']) ? $_GET['id'] : die();

        $query = "SELECT id, name FROM companies WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            http_response_code(200);
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Company not found."]);
        }
    }
}
?>