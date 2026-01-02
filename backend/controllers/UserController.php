<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../models/User.php';
include_once __DIR__ . '/../utils/JWTHandler.php';
include_once __DIR__ . '/../models/ActivityLog.php';

class UserController
{
    private $db;
    private $user;
    private $activityLog;
    private $jwt;
    private $user_role;
    private $user_id; // Restored private $user_id property
    private $company_id;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->user = new User($this->db);
        $this->activityLog = new ActivityLog($this->db);
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

    private function checkAdminOrManagerOrOwner()
    {
        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            exit();
        }
    }

    public function getOne($id)
    {
        $this->authenticate();

        // Allow if admin/manager/owner OR if requesting own profile
        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner' && $this->user_id != $id) {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            return;
        }

        $this->user->id = $id;

        if ($this->user->getById()) {
            // Data Isolation: Admin/Manager/Owner can only view users in their company
            if ($this->user_role === 'admin' || $this->user_role === 'manager' || $this->user_role === 'owner') {
                if ($this->user->company_id != $this->company_id) {
                    http_response_code(403);
                    echo json_encode(array("message" => "Access denied. User belongs to another company."));
                    return;
                }
            }

            http_response_code(200);
            echo json_encode(array(
                "id" => $this->user->id,
                "username" => $this->user->username,
                "email" => $this->user->email,
                "role" => $this->user->role,
                "points" => $this->user->points,
                "profile_pic" => $this->user->profile_pic,
                "created_at" => $this->user->created_at,
                "company_id" => $this->user->company_id
            ));
        } else {
            http_response_code(404);
            echo json_encode(array("message" => "User not found."));
        }
    }

    public function getAll()
    {
        $this->authenticate();
        $this->checkAdminOrManagerOrOwner();

        // Filter by company_id
        if ($this->company_id) {
            $query = "SELECT u.id, u.username, u.email, u.role, u.points, u.profile_pic, u.created_at, u.company_id, c.name as company_name 
                      FROM users u 
                      LEFT JOIN companies c ON u.company_id = c.id
                      WHERE u.company_id = ? 
                      ORDER BY u.created_at DESC";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(1, $this->company_id);
        } else {
            // SECURITY FIX: If no company_id is present (e.g. misconfigured owner), do NOT show all users.
            // This prevents data leakage across companies.
            http_response_code(200);
            echo json_encode(array());
            return;
        }

        $stmt->execute();
        $num = $stmt->rowCount();

        if ($num > 0) {
            $users_arr = array();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                extract($row);
                $user_item = array(
                    "id" => $id,
                    "username" => $username,
                    "email" => $email,
                    "role" => $role,
                    "points" => $points,
                    "company_id" => isset($company_id) ? $company_id : null,
                    "company_name" => isset($company_name) ? $company_name : null,
                    "profile_pic" => $profile_pic,
                    "created_at" => $created_at
                );
                array_push($users_arr, $user_item);
            }
            http_response_code(200);
            echo json_encode($users_arr);
        } else {
            http_response_code(200);
            echo json_encode(array());
        }
    }

    public function create()
    {
        $this->authenticate();
        $this->checkAdminOrManagerOrOwner();

        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->username) && !empty($data->email) && !empty($data->password)) {
            $this->user->username = $data->username;
            $this->user->email = $data->email;

            if ($this->user->emailExists()) {
                http_response_code(400);
                echo json_encode(array("message" => "Email already exists."));
                return;
            }

            $this->user->password = password_hash($data->password, PASSWORD_BCRYPT);

            // STRICT ROLE HIERARCHY
            $requested_role = isset($data->role) ? $data->role : 'user';

            // Only Owner can create Admin or Manager
            if (($requested_role === 'admin' || $requested_role === 'manager') && $this->user_role !== 'owner') {
                http_response_code(403);
                echo json_encode(array("message" => "Access denied. Only Owners can create Admins or Managers."));
                return;
            }

            $this->user->role = $requested_role;

            // STRICT ENFORCEMENT: Inherit Company ID from Creator
            if (empty($this->company_id)) {
                http_response_code(403);
                echo json_encode(array("message" => "Critical Error: You are not assigned to a company. Cannot create users."));
                return;
            }
            // IGNORE frontend input for company_id. Trust only the session.
            $this->user->company_id = $this->company_id;

            if ($this->user->create()) {
                http_response_code(201);
                echo json_encode(array("message" => "User was created."));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to create user."));
            }
        } else {
            http_response_code(400);
            echo json_encode(array("message" => "Incomplete data."));
        }
    }

    public function delete($id)
    {
        $this->authenticate();
        $this->checkAdminOrManagerOrOwner();

        $query = "DELETE FROM users WHERE id = :id AND company_id = :company_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':company_id', $this->company_id);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(array("message" => "User was deleted."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to delete user."));
        }
    }


    public function getUserActivity($id)
    {
        $this->authenticate();
        $this->checkAdminOrManagerOrOwner();

        $stmt = $this->activityLog->getByUserId($id);
        $activities_arr = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $activity_item = array(
                "id" => $row['id'],
                "action" => $row['action'],
                "details" => $row['details'],
                "task_id" => $row['task_id'],
                "task_title" => $row['task_title'],
                "created_at" => $row['created_at']
            );
            array_push($activities_arr, $activity_item);
        }
        http_response_code(200);
        echo json_encode($activities_arr);
    }

    public function getAllActivity()
    {
        $this->authenticate();
        $this->checkAdminOrManagerOrOwner();

        $stmt = $this->activityLog->getAll($this->company_id);
        $activities_arr = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $activity_item = array(
                "id" => $row['id'],
                "user_id" => $row['user_id'] ?? null,
                "username" => $row['username'] ?? 'System',
                "profile_pic" => $row['profile_pic'] ?? null,
                "action" => $row['action'] ?? 'unknown',
                "details" => $row['details'] ?? '',
                "task_id" => $row['task_id'] ?? null,
                "task_title" => $row['task_title'] ?? 'N/A',
                "created_at" => $row['created_at'] ?? date('Y-m-d H:i:s')
            );
            array_push($activities_arr, $activity_item);
        }
        http_response_code(200);
        echo json_encode($activities_arr);
    }



    public function uploadProfilePic()
    {
        $this->authenticate();
        $id = $this->user_id; // Use authenticated user ID

        // Check if it's a file upload or a URL update
        if (isset($_FILES['profile_pic'])) {
            $target_dir = __DIR__ . "/../uploads/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            $file_extension = pathinfo($_FILES["profile_pic"]["name"], PATHINFO_EXTENSION);
            $new_filename = "profile_" . $id . "_" . time() . "." . $file_extension;
            $target_file = $target_dir . $new_filename;

            if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_file)) {
                // Save relative path for frontend usage
                // Frontend MEDIA_URL is .../backend/, so we just need uploads/filename
                $url = "uploads/" . $new_filename;
                $this->user->id = $id;
                if ($this->user->updateProfilePic($url)) {
                    http_response_code(200);
                    echo json_encode(array("message" => "Profile picture uploaded.", "url" => $url));
                } else {
                    http_response_code(503);
                    echo json_encode(array("message" => "Database update failed."));
                }
            } else {
                http_response_code(500);
                echo json_encode(array("message" => "File upload failed."));
            }
        } else {
            // Check for JSON body (Avatar URL)
            $data = json_decode(file_get_contents("php://input"));
            if (!empty($data->avatar_url)) {
                $this->user->id = $id;
                if ($this->user->updateProfilePic($data->avatar_url)) {
                    http_response_code(200);
                    echo json_encode(array("message" => "Avatar updated.", "url" => $data->avatar_url));
                } else {
                    http_response_code(503);
                    echo json_encode(array("message" => "Database update failed."));
                }
            } else {
                http_response_code(400);
                echo json_encode(array("message" => "No file or URL provided."));
            }
        }
    }

    public function grantTime($id)
    {
        $this->authenticate();
        $this->checkAdminOrManagerOrOwner();

        // TODO: Implement actual logic for granting time.
        // Currently just logging the request and returning success to prevent crashes.

        error_log("grantTime called for user ID: " . $id);

        http_response_code(200);
        echo json_encode(array("message" => "Time extension granted (Placeholder)."));
    }
}
?>