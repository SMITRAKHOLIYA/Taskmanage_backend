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
    private $company_id; // Added property

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
            $this->recurringTask->company_id = $this->company_id; // Assign company_id from session

            if ($this->recurringTask->create()) {
                error_log("Recurring Task Created ID: " . $this->recurringTask->id);
                error_log("Start Date: " . $this->recurringTask->start_date);
                error_log("Server Time: " . time() . " (" . date('Y-m-d H:i:s') . ")");
                error_log("Comparison: " . strtotime($this->recurringTask->start_date) . " <= " . time());

                // Generate first task immediately if start_date <= today
                // This applies to BOTH 'schedule' and 'completion' triggers for the first run
                if (strtotime($this->recurringTask->start_date) <= time()) {
                    error_log("Triggering immediate generation for Recurring Task ID: " . $this->recurringTask->id);

                    $this->task->title = $this->recurringTask->title;
                    $this->task->description = $this->recurringTask->description;
                    $this->task->priority = $this->recurringTask->priority;
                    $this->task->status = 'pending';
                    $this->task->assigned_to = $this->recurringTask->assigned_to;
                    $this->task->created_by = $this->recurringTask->created_by;
                    $this->task->project_id = $this->recurringTask->project_id;
                    $this->task->recurring_task_id = $this->recurringTask->id;
                    $this->task->questions = $this->recurringTask->questions;
                    $this->task->company_id = $this->company_id; // Inherit company_id

                    // For 'schedule', due date is start_date (or today) + frequency? 
                    // Usually first task due date = start date.
                    // For 'completion', it's usually immediate.
                    $this->task->due_date = $this->recurringTask->start_date . ' 23:59:59';

                    if ($this->task->create()) {
                        error_log("Immediate task generated successfully. Task ID: " . $this->task->id);
                        // For 'schedule', we must also update the next_run_date
                        if ($this->recurringTask->recurrence_trigger === 'schedule') {
                            $this->recurringTask->updateNextRunDate(
                                $this->recurringTask->id,
                                $this->recurringTask->frequency,
                                $this->recurringTask->start_date
                            );
                        }
                    } else {
                        error_log("Failed to generate immediate task.");
                    }
                } else {
                    error_log("Immediate generation skipped. Start date is in future.");
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

        // Filter by company_id and role
        $stmt = $this->recurringTask->getAll($this->user_id, $this->user_role, $this->company_id);
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