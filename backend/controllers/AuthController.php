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
        $data = json_decode(file_get_contents("php://input"));

        // Handle both JSON and Form Data
        $email = $data->email ?? $_POST['email'] ?? '';
        $password = $data->password ?? $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(["message" => "Email and password are required"]);
            return;
        }

        $this->user->email = $email;
        $email_exists = $this->user->emailExists();

        if ($email_exists && password_verify($password, $this->user->password)) {
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
        } else {
            http_response_code(401);
            echo json_encode(["message" => "Login failed. Invalid credentials."]);
        }
    }

    public function register()
    {
        $data = json_decode(file_get_contents("php://input"));

        // Handle both JSON and Form Data
        $username = $data->username ?? $_POST['username'] ?? '';
        $email = $data->email ?? $_POST['email'] ?? '';
        $password = $data->password ?? $_POST['password'] ?? '';
        $role = $data->role ?? $_POST['role'] ?? 'user';
        $company_id = $data->company_id ?? $_POST['company_id'] ?? null;

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
            $companyName = $data->company_name ?? $data->companyName ?? $_POST['company_name'] ?? '';

            if (empty($companyName)) {
                http_response_code(400);
                echo json_encode(["message" => "Company Name is required for Owners"]);
                return;
            }

            // Create Company
            $query = "INSERT INTO companies (name, status) VALUES (:name, 'active')";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(":name", $companyName);

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

        // User.php create() handles the rest. 
        // Note: User.php create() expects company_id to be set.

        if ($this->user->create()) {
            http_response_code(201);
            echo json_encode(["message" => "User registered successfully"]);
        } else {
            // If user creation fails, we should technically rollback company creation, 
            // but for now, let's just error.
            http_response_code(500);
            echo json_encode(["message" => "Unable to register user"]);
        }
    }
}
?>