<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../models/RecurringTask.php';
include_once __DIR__ . '/../models/Task.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class RecurringTaskController
{
    private $db;
    private $recurringTask;
    private $task;
    private $jwt;
    private $user_id;
    private $user_role;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->recurringTask = new RecurringTask($this->db);
        $this->task = new Task($this->db);
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
            return false;
        }

        $token = str_replace('Bearer ', '', $authHeader);
        $decoded = $this->jwt->validate_jwt($token);

        if (!$decoded) {
            return false;
        }

        $this->user_id = $decoded->id;
        $this->user_role = $decoded->role;
        $this->company_id = isset($decoded->company_id) ? $decoded->company_id : null;
        return true;
    }

    public function create()
    {
        if (!$this->authenticate()) {
            http_response_code(401);
            echo json_encode(array("message" => "Unauthorized."));
            return;
        }

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        if (
            !empty($data->title) &&
            !empty($data->frequency) &&
            !empty($data->start_date) &&
            !empty($data->assigned_to)
        ) {
            $this->recurringTask->title = $data->title;
            $this->recurringTask->description = $data->description ?? '';
            $this->recurringTask->priority = $data->priority ?? 'medium';
            $this->recurringTask->assigned_to = $data->assigned_to;
            $this->recurringTask->project_id = $data->project_id ?? null;
            $this->recurringTask->frequency = $data->frequency;
            $this->recurringTask->start_date = $data->start_date;
            $this->recurringTask->next_run_date = $data->start_date; // Initial run date is start date
            $this->recurringTask->created_by = $this->user_id;
            $this->recurringTask->recurrence_trigger = $data->recurrence_trigger ?? 'schedule';
            $this->recurringTask->questions = isset($data->questions) ? json_encode($data->questions) : null;

            if ($this->recurringTask->create()) {
                // If trigger is completion and start_date <= today, generate first task
                if (
                    $this->recurringTask->recurrence_trigger === 'completion' &&
                    strtotime($this->recurringTask->start_date) <= time()
                ) {

                    $this->task->title = $this->recurringTask->title;
                    $this->task->description = $this->recurringTask->description;
                    $this->task->priority = $this->recurringTask->priority;
                    $this->task->status = 'pending';
                    $this->task->assigned_to = $this->recurringTask->assigned_to;
                    $this->task->created_by = $this->recurringTask->created_by;
                    $this->task->project_id = $this->recurringTask->project_id;
                    $this->task->recurring_task_id = $this->recurringTask->id;
                    $this->task->questions = $this->recurringTask->questions;
                    $this->task->due_date = date('Y-m-d 23:59:59');

                    $this->task->create();
                }

                http_response_code(201);
                echo json_encode(array("message" => "Recurring task created successfully."));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to create recurring task."));
            }
        } else {
            http_response_code(400);
            echo json_encode(array("message" => "Incomplete data."));
        }
    }

    public function getOne($id)
    {
        if (!$this->authenticate()) {
            http_response_code(401);
            echo json_encode(array("message" => "Unauthorized."));
            return;
        }

        $this->recurringTask->id = $id;
        if ($this->recurringTask->getOne()) {
            http_response_code(200);
            echo json_encode($this->recurringTask);
        } else {
            http_response_code(404);
            echo json_encode(array("message" => "Recurring task not found."));
        }
    }

    public function getAll()
    {
        if (!$this->authenticate()) {
            http_response_code(401);
            echo json_encode(array("message" => "Unauthorized."));
            return;
        }

        // Filter by company_id if needed, but for now we'll use user_id or all
        $stmt = $this->recurringTask->getAll();
        $tasks_arr = array();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            array_push($tasks_arr, $row);
        }

        http_response_code(200);
        echo json_encode($tasks_arr);
    }

    public function generateTasks()
    {
        // This endpoint can be called by anyone (or restricted) to trigger generation.
        // For security, let's require authentication but allow any user to trigger it (e.g. dashboard load)
        if (!$this->authenticate()) {
            http_response_code(401);
            echo json_encode(array("message" => "Unauthorized."));
            return;
        }

        $stmt = $this->recurringTask->getDueTasks();
        $count = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Create a new Task instance
            $this->task->title = $row['title'];
            $this->task->description = $row['description'];
            $this->task->priority = $row['priority'];
            $this->task->status = 'pending';
            $this->task->assigned_to = $row['assigned_to'];
            $this->task->created_by = $row['created_by']; // Or system user? Let's keep original creator
            $this->task->project_id = $row['project_id'];
            $this->task->recurring_task_id = $row['id'];
            $this->task->questions = $row['questions'];

            // Set due date based on frequency? Or just today?
            // Let's set due date to today + 1 day for now, or maybe same as run date?
            // User didn't specify due date logic, so let's assume due date is end of the day of generation
            $this->task->due_date = date('Y-m-d 23:59:59');

            if ($this->task->create()) {
                // Update next run date
                $this->recurringTask->updateNextRunDate($row['id'], $row['frequency'], $row['next_run_date']);
                $count++;
            }
        }

        http_response_code(200);
        echo json_encode(array("message" => "Generated $count recurring tasks."));
    }
}
?>