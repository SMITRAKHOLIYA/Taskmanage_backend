<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../models/Project.php';
include_once __DIR__ . '/../models/User.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class ProjectController
{
    private $db;
    private $project;
    private $user;
    private $jwt;
    private $user_id;
    private $user_role;
    private $company_id;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->project = new Project($this->db);
        $this->user = new User($this->db);
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

        $this->user_id = $decoded->id;
        $this->user_role = $decoded->role;
        $this->company_id = isset($decoded->company_id) ? $decoded->company_id : null;
        return true;
    }

    public function getAll()
    {
        $this->authenticate();

        $stmt = $this->project->getAll($this->user_id, $this->user_role, $this->company_id);
        $projects_arr = array();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            extract($row);

            // Get progress for each project
            $this->project->id = $id;
            $progress = $this->project->getProgress();

            $project_item = array(
                "id" => $id,
                "title" => $title,
                "description" => $description,
                "status" => $status,
                "created_by" => $created_by,
                "creator_name" => $creator_name,
                "created_at" => $created_at,
                "progress" => $progress
            );
            array_push($projects_arr, $project_item);
        }

        http_response_code(200);
        echo json_encode($projects_arr);
    }

    public function getOne($id)
    {
        $this->authenticate();
        $this->project->id = $id;

        $row = $this->project->getById();

        if ($row) {
            // Check access (Admin/Manager or Member)
            $hasAccess = false;
            if ($this->user_role === 'admin' || $this->user_role === 'manager' || $this->user_role === 'owner' || $row['created_by'] == $this->user_id) {
                $hasAccess = true;
            } else {
                // Check membership
                $stmt = $this->project->getMembers();
                while ($member = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($member['id'] == $this->user_id) {
                        $hasAccess = true;
                        break;
                    }
                }
            }

            if (!$hasAccess) {
                http_response_code(403);
                echo json_encode(array("message" => "Access denied."));
                return;
            }

            // Get Members
            $members_stmt = $this->project->getMembers();
            $members = [];
            while ($member = $members_stmt->fetch(PDO::FETCH_ASSOC)) {
                $members[] = $member;
            }

            // Get Progress
            $progress = $this->project->getProgress();

            $row['members'] = $members;
            $row['progress'] = $progress;

            http_response_code(200);
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(array("message" => "Project not found."));
        }
    }

    public function create()
    {
        $this->authenticate();

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied. Only Admin/Manager/Owner can create projects."));
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->title)) {
            $this->project->title = $data->title;
            $this->project->description = $data->description ?? '';
            $this->project->status = 'active';
            $this->project->created_by = $this->user_id;

            if ($this->project->create()) {
                // Add creator as member by default? Maybe not necessary if they are admin.
                // But let's add them to be safe if they want to see it in "My Projects"
                // $this->project->addMember($this->user_id);

                // Add assigned members
                if (!empty($data->members) && is_array($data->members)) {
                    foreach ($data->members as $member_id) {
                        $this->project->addMember($member_id);
                    }
                }

                http_response_code(201);
                echo json_encode(array("message" => "Project created.", "id" => $this->project->id));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to create project."));
            }
        } else {
            http_response_code(400);
            echo json_encode(array("message" => "Incomplete data."));
        }
    }

    public function update($id)
    {
        $this->authenticate();
        $data = json_decode(file_get_contents("php://input"));

        $this->project->id = $id;

        if ($this->project->getById()) {
            if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner' && $this->project->created_by != $this->user_id) {
                http_response_code(403);
                echo json_encode(array("message" => "Access denied."));
                return;
            }

            $this->project->title = $data->title ?? $this->project->title;
            $this->project->description = $data->description ?? $this->project->description;
            $this->project->status = $data->status ?? $this->project->status;

            if ($this->project->update()) {
                // Update members if provided
                if (isset($data->members) && is_array($data->members)) {
                    // This is a bit complex: remove old ones, add new ones?
                    // For simplicity, let's assume we just add new ones or we have a separate endpoint for managing members.
                    // But usually "Edit Project" allows changing members.
                    // Let's clear and re-add for simplicity (inefficient but works for small teams)
                    // Or better: get current members, diff, add/remove.

                    // Simple approach:
                    // 1. Get current members
                    $stmt = $this->project->getMembers();
                    $current_members = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $current_members[] = $row['id'];
                    }

                    // 2. Add new members
                    foreach ($data->members as $new_member_id) {
                        if (!in_array($new_member_id, $current_members)) {
                            $this->project->addMember($new_member_id);
                        }
                    }

                    // 3. Remove members not in new list
                    foreach ($current_members as $old_member_id) {
                        if (!in_array($old_member_id, $data->members)) {
                            $this->project->removeMember($old_member_id);
                        }
                    }
                }

                http_response_code(200);
                echo json_encode(array("message" => "Project updated."));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to update project."));
            }
        } else {
            http_response_code(404);
            echo json_encode(array("message" => "Project not found."));
        }
    }

    public function delete($id)
    {
        $this->authenticate();

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            return;
        }

        $this->project->id = $id;

        if ($this->project->delete()) {
            http_response_code(200);
            echo json_encode(array("message" => "Project deleted."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to delete project."));
        }
    }
}
?>