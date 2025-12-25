<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../models/TaskAnswer.php';
include_once __DIR__ . '/../utils/JWTHandler.php';
include_once __DIR__ . '/../models/ActivityLog.php';

class TaskAnswerController
{
    private $db;
    private $taskAnswer;
    private $jwt;
    private $user_id;
    private $activityLog;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->taskAnswer = new TaskAnswer($this->db);
        $this->jwt = new JWTHandler();
        $this->activityLog = new ActivityLog($this->db);
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
        return true;
    }

    public function save()
    {
        if (!$this->authenticate()) {
            http_response_code(401);
            echo json_encode(array("message" => "Unauthorized."));
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        // strict check: User must be assigned to the task
        include_once __DIR__ . '/../models/Task.php';
        $taskModel = new Task($this->db);
        $taskModel->id = $data->task_id ?? null;

        if (!$taskModel->id || !$taskModel->getById()) {
            http_response_code(404);
            echo json_encode(array("message" => "Task not found."));
            return;
        }

        // Allow if user is assigned OR if user is the creator (optional, but request said "assigned user only")
        // Sticking strictly to "assigned user only" as requested.
        if ($taskModel->assigned_to != $this->user_id) {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied. Only the assigned user can answer."));
            return;
        }

        if (
            !empty($data->task_id) &&
            !empty($data->question_id) &&
            !empty($data->answer_type) &&
            isset($data->answer_value)
        ) {
            $this->taskAnswer->task_id = $data->task_id;
            $this->taskAnswer->question_id = $data->question_id;
            $this->taskAnswer->user_id = $this->user_id;
            $this->taskAnswer->answer_type = $data->answer_type;
            $this->taskAnswer->answer_value = is_array($data->answer_value) ? json_encode($data->answer_value) : $data->answer_value;

            // Check if answer exists
            $stmt = $this->taskAnswer->checkExisting($data->task_id, $data->question_id, $this->user_id);
            if ($stmt->rowCount() > 0) {
                // Update
                if ($this->taskAnswer->update()) {
                    http_response_code(200);
                    echo json_encode(array("message" => "Answer updated successfully."));
                } else {
                    http_response_code(503);
                    echo json_encode(array("message" => "Unable to update answer."));
                }
            } else {
                // Create
                if ($this->taskAnswer->create()) {
                    // Log activity
                    $this->activityLog->create($this->user_id, "Answer Submitted", "Submitted answer for question ID: " . $data->question_id, $data->task_id);

                    http_response_code(201);
                    echo json_encode(array("message" => "Answer saved successfully."));
                } else {
                    http_response_code(503);
                    echo json_encode(array("message" => "Unable to save answer."));
                }
            }
        } else {
            http_response_code(400);
            echo json_encode(array("message" => "Incomplete data."));
        }
    }

    public function getByTask($taskId)
    {
        if (!$this->authenticate()) {
            http_response_code(401);
            echo json_encode(array("message" => "Unauthorized."));
            return;
        }

        $stmt = $this->taskAnswer->getByTask($taskId);
        $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($answers) > 0) {
            $answers_arr = array();
            foreach ($answers as $row) {
                // extract($row); // Avoid extract for better clarity and safety
                $answer_item = array(
                    "id" => $row['id'],
                    "task_id" => $row['task_id'],
                    "question_id" => $row['question_id'],
                    "user_id" => $row['user_id'],
                    "username" => isset($row['username']) ? $row['username'] : null,
                    "profile_pic" => isset($row['profile_pic']) ? $row['profile_pic'] : null,
                    "answer_type" => $row['answer_type'],
                    "answer_value" => $row['answer_value'],
                    "created_at" => $row['created_at']
                );
                array_push($answers_arr, $answer_item);
            }
            http_response_code(200);
            echo json_encode($answers_arr);
        } else {
            http_response_code(200);
            echo json_encode(array());
        }
    }
}
?>