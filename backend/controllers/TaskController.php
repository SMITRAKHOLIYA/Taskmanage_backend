<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../models/Task.php';
include_once __DIR__ . '/../models/User.php';
include_once __DIR__ . '/../models/ActivityLog.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class TaskController
{
    private $db;

    private $task;
    private $user;
    private $activityLog;
    private $jwt;
    private $user_id;
    private $user_role;
    private $company_id;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->task = new Task($this->db);
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

        $this->user_id = $decoded->id;
        $this->user_role = $decoded->role;
        $this->company_id = isset($decoded->company_id) ? $decoded->company_id : null;
        return true;
    }

    public function getAll()
    {
        $this->authenticate();

        $params = [
            'page' => isset($_GET['page']) ? $_GET['page'] : 1,
            'limit' => isset($_GET['limit']) ? $_GET['limit'] : 10,
            'sort_by' => isset($_GET['sort_by']) ? $_GET['sort_by'] : 'created_at',
            'sort_order' => isset($_GET['sort_order']) ? $_GET['sort_order'] : 'desc',
            'search' => isset($_GET['search']) ? $_GET['search'] : '',
            'priority' => isset($_GET['priority']) ? $_GET['priority'] : '',
            'status' => isset($_GET['status']) ? $_GET['status'] : '',
            'project_id' => isset($_GET['project_id']) ? $_GET['project_id'] : '',
            'parent_id' => isset($_GET['parent_id']) ? $_GET['parent_id'] : '',
            'exclude_status' => isset($_GET['exclude_status']) ? $_GET['exclude_status'] : ''
        ];

        $stmt = $this->task->getAll($this->user_id, $this->user_role, $params, $this->company_id);
        $total_items = $this->task->countAll($this->user_id, $this->user_role, $params, $this->company_id);

        $tasks_arr = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            extract($row);
            $task_item = array(
                "id" => $id,
                "title" => $title,
                "description" => $description,
                "priority" => $priority,
                "status" => $status,
                "due_date" => $due_date,
                "created_by" => $created_by,
                "assigned_to" => $assigned_to,
                "assigned_user_name" => $assigned_user_name,
                "creator_name" => $creator_name,
                "created_at" => $created_at,
                "project_id" => $project_id,
                "parent_id" => $parent_id,
                "questions" => $questions
            );
            array_push($tasks_arr, $task_item);
        }

        $response = [
            'data' => $tasks_arr,
            'meta' => [
                'current_page' => (int) $params['page'],
                'per_page' => (int) $params['limit'],
                'total_items' => (int) $total_items,
                'total_pages' => ceil($total_items / $params['limit'])
            ]
        ];

        http_response_code(200);
        echo json_encode($response);
    }

    public function getOne($id)
    {
        $this->authenticate();
        $this->task->id = $id;

        if ($this->task->getById()) {
            // Check permission
            if (
                $this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner' &&
                $this->task->assigned_to != $this->user_id && $this->task->created_by != $this->user_id
            ) {
                http_response_code(403);
                echo json_encode(array("message" => "Access denied."));
                return;
            }

            $task_item = array(
                "id" => $this->task->id,
                "title" => $this->task->title,
                "description" => $this->task->description,
                "priority" => $this->task->priority,
                "status" => $this->task->status,
                "due_date" => $this->task->due_date,
                "created_by" => $this->task->created_by,
                "assigned_to" => $this->task->assigned_to,
                "assigned_user_name" => $this->task->assigned_user_name ?? null, // Assuming getById populates this or we need to fetch it
                "creator_name" => $this->task->creator_name ?? null,
                "created_at" => $this->task->created_at,
                "project_id" => $this->task->project_id,
                "project_title" => $this->task->project_title ?? null, // Add project title
                "parent_id" => $this->task->parent_id,
                "parent_task_title" => $this->task->parent_task_title ?? null, // Add parent task title
                "recurring_task_id" => $this->task->recurring_task_id ?? null,
                "questions" => $this->task->questions ?? null,
            );
            // Note: getById in Task.php fetches joined names, so they should be available in the row returned.
            // But Task.php getById returns the row, it also sets properties.
            // However, properties like assigned_user_name are not properties of the class Task, so they might not be set on $this->task.
            // Let's check Task.php getById implementation.
            // It returns $row. So we should use the returned row.

            // Re-implementing correctly:
            $row = $this->task->getById(); // This returns the row array
            if ($row) {
                // Permission check again with row data to be safe
                if (
                    $this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner' &&
                    $row['assigned_to'] != $this->user_id && $row['created_by'] != $this->user_id
                ) {
                    http_response_code(403);
                    echo json_encode(array("message" => "Access denied."));
                    return;
                }

                http_response_code(200);
                echo json_encode($row);
            } else {
                http_response_code(404);
                echo json_encode(array("message" => "Task not found."));
            }
        } else {
            http_response_code(404);
            echo json_encode(array("message" => "Task not found."));
        }
    }

    public function create()
    {
        $this->authenticate();

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied. Only Admin/Manager/Owner can create tasks."));
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        if (!empty($data->title)) {
            $this->task->title = $data->title;
            $this->task->description = $data->description ?? '';
            $this->task->priority = $data->priority ?? 'medium';
            $this->task->status = 'pending';
            $this->task->due_date = !empty($data->due_date) ? $data->due_date : null;
            $this->task->created_by = $this->user_id;
            $this->task->assigned_to = !empty($data->assigned_to) ? $data->assigned_to : $this->user_id;
            $this->task->project_id = !empty($data->project_id) ? $data->project_id : null;
            $this->task->parent_id = !empty($data->parent_id) ? $data->parent_id : null;
            $this->task->questions = isset($data->questions) ? $data->questions : null;
            $this->task->company_id = $this->company_id; // Assign company_id from session
            $this->task->requires_execution_workflow = isset($data->requires_execution_workflow) ? $data->requires_execution_workflow : 0; // Default to false

            error_log("Creating task: " . json_encode($data));
            error_log("Task Object: " . json_encode($this->task));

            if ($this->task->create()) {
                $this->task->id = $this->db->lastInsertId();

                // Fetch Creator Details for Notification
                $creatorQuery = "SELECT username, role FROM users WHERE id = :id";
                $creatorStmt = $this->db->prepare($creatorQuery);
                $creatorStmt->bindParam(":id", $this->user_id);
                $creatorStmt->execute();
                $creator = $creatorStmt->fetch(PDO::FETCH_ASSOC);
                $creatorName = $creator ? $creator['username'] . " (" . ucfirst($creator['role']) . ")" : "Admin";

                // Create Notification
                include_once __DIR__ . '/NotificationController.php';
                $notificationController = new NotificationController();

                // Notification for Assignee (if different from creator)
                if ($this->task->assigned_to != $this->user_id) {
                    $dueDateStr = $this->task->due_date ? " Due: " . $this->task->due_date : "";
                    $notificationController->createNotification(
                        $this->task->assigned_to,
                        $this->task->id,
                        "New Task Assigned: '{$this->task->title}' by {$creatorName}.{$dueDateStr}",
                        'assignment'
                    );
                }

                // Log Activity
                $this->activityLog->create($this->user_id, "Task Created", "Created task: " . $this->task->title, $this->task->id);

                http_response_code(201);
                echo json_encode(array("message" => "Task was created."));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to create task."));
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

        $this->task->id = $id;

        if ($this->task->getById()) {
            $oldStatus = $this->task->status;
            $newStatus = $data->status ?? $this->task->status;

            // PERMISSIONS
            $isManager = in_array($this->user_role, ['admin', 'manager', 'owner']);
            $isAssignee = ($this->task->assigned_to == $this->user_id);

            // 1. Status Change Check
            if ($oldStatus !== $newStatus) {
                // If not manager and not assignee, can't change status generally (though getOne usually limits access already)
                // But specifically:

                // If Manager is changing status and is NOT assignee: Require Reason
                if ($isManager && !$isAssignee) {
                    $note = $data->note ?? $data->override_reason ?? '';
                    if (empty($note) || trim($note) === '') {
                        http_response_code(403);
                        echo json_encode(["message" => "Changing status requires an override reason."]);
                        return;
                    }
                    // Save to task model
                    $this->task->last_override_reason = $note;

                    // Log the override
                    $this->activityLog->create($this->user_id, "Status Override", "Changed status from $oldStatus to $newStatus. Reason: $note", $this->task->id);
                }

                // Existing logic regarding reverting logic...
                if (!$isManager && $oldStatus !== $newStatus) {
                    if ($oldStatus === 'completed') {
                        http_response_code(403);
                        echo json_encode(["message" => "Cannot revert completed task."]);
                        return;
                    }
                    if ($oldStatus === 'in_progress' && $newStatus === 'pending') {
                        http_response_code(403);
                        echo json_encode(["message" => "Cannot move task back to pending."]);
                        return;
                    }
                }
            }

            // Assign other fields...
            $this->task->title = $data->title ?? $this->task->title;
            $this->task->description = $data->description ?? $this->task->description;
            $this->task->priority = $data->priority ?? $this->task->priority;
            $this->task->due_date = $data->due_date ?? $this->task->due_date;
            $this->task->assigned_to = $data->assigned_to ?? $this->task->assigned_to;
            $this->task->project_id = $data->project_id ?? $this->task->project_id;
            $this->task->parent_id = $data->parent_id ?? $this->task->parent_id;
            $this->task->questions = isset($data->questions) ? $data->questions : $this->task->questions;
            $this->task->status = $newStatus;

            // Allow Admin/Manager/Owner to toggle workflow requirement
            if (in_array($this->user_role, ['admin', 'manager', 'owner'])) {
                $this->task->requires_execution_workflow = isset($data->requires_execution_workflow) ? $data->requires_execution_workflow : $this->task->requires_execution_workflow;
            }

            if ($this->task->update()) {
                // Award points if task is completed and wasn't before
                if ($newStatus === 'completed' && $oldStatus !== 'completed') {
                    // Award 10 points
                    $query = "UPDATE users SET points = points + 10 WHERE id = :id";
                    $stmt = $this->db->prepare($query);
                    $stmt->bindParam(':id', $this->task->assigned_to);
                    $stmt->execute();
                }

                // Check for Recurring Task Completion Trigger
                if ($newStatus === 'completed' && $oldStatus !== 'completed' && !empty($this->task->recurring_task_id)) {
                    include_once __DIR__ . '/../models/RecurringTask.php';
                    $recurringTaskModel = new RecurringTask($this->db);
                    $recurringTaskModel->id = $this->task->recurring_task_id;

                    // Fetch recurring task details manually since we don't have getById in RecurringTask model yet
                    // Or we can just query directly here for simplicity
                    $queryRT = "SELECT * FROM recurring_tasks WHERE id = :id";
                    $stmtRT = $this->db->prepare($queryRT);
                    $stmtRT->bindParam(':id', $this->task->recurring_task_id);
                    $stmtRT->execute();
                    $rtRow = $stmtRT->fetch(PDO::FETCH_ASSOC);

                    if ($rtRow && $rtRow['recurrence_trigger'] === 'completion') {
                        // Generate next task
                        $newTask = new Task($this->db);
                        $newTask->title = $rtRow['title'];
                        $newTask->description = $rtRow['description'];
                        $newTask->priority = $rtRow['priority'];
                        $newTask->status = 'pending';
                        $newTask->assigned_to = $rtRow['assigned_to'];
                        $newTask->created_by = $rtRow['created_by'];
                        $newTask->project_id = $rtRow['project_id'];
                        $newTask->recurring_task_id = $rtRow['id'];
                        $newTask->questions = $rtRow['questions'];

                        // Calculate due date based on frequency from TODAY (completion date)
                        $nextDate = new DateTime(); // Today
                        if ($rtRow['frequency'] === 'daily') {
                            $nextDate->modify('+1 day');
                        } elseif ($rtRow['frequency'] === 'weekly') {
                            $nextDate->modify('+1 week');
                        } elseif ($rtRow['frequency'] === 'monthly') {
                            $nextDate->modify('+1 month');
                        }
                        $newTask->due_date = $nextDate->format('Y-m-d 23:59:59');

                        if ($newTask->create()) {
                            // Log generation
                            $this->activityLog->create($this->user_id, "Recurring Task Generated", "Generated next task from completion of '" . $this->task->title . "'", $newTask->id);
                        }
                    }
                }

                // Log Activity
                $activityMessage = "Updated task: " . $this->task->title;
                if ($oldStatus !== $newStatus) {
                    $activityMessage .= ". Status changed from $oldStatus to $newStatus";
                }
                $this->activityLog->create($this->user_id, "Task Updated", $activityMessage, $this->task->id);

                http_response_code(200);
                echo json_encode(array("message" => "Task was updated."));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to update task."));
            }
        } else {
            http_response_code(404);
            echo json_encode(array("message" => "Task not found."));
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

        $this->task->id = $id;

        if ($this->task->delete()) {
            // Log Activity
            $this->activityLog->create($this->user_id, "Task Deleted", "Deleted task ID: " . $id, $id);

            http_response_code(200);
            echo json_encode(array("message" => "Task was deleted."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to delete task."));
        }
    }
    public function getTrash()
    {
        $this->authenticate();

        $stmt = $this->task->getTrash($this->user_id, $this->user_role, $this->company_id);
        $tasks_arr = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            extract($row);
            $task_item = array(
                "id" => $id,
                "title" => $title,
                "description" => $description,
                "priority" => $priority,
                "status" => $status,
                "due_date" => $due_date,
                "created_by" => $created_by,
                "assigned_to" => $assigned_to,
                "assigned_user_name" => $assigned_user_name,
                "creator_name" => $creator_name,
                "created_at" => $created_at,
                "deleted_at" => $deleted_at
            );
            array_push($tasks_arr, $task_item);
        }

        http_response_code(200);
        echo json_encode($tasks_arr);
    }

    public function restore($id)
    {
        $this->authenticate();

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            return;
        }

        $this->task->id = $id;

        if ($this->task->restore()) {
            // Log Activity
            $this->activityLog->create($this->user_id, "Task Restored", "Restored task ID: " . $id, $id);

            http_response_code(200);
            echo json_encode(array("message" => "Task was restored."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to restore task."));
        }
    }

    public function forceDelete($id)
    {
        $this->authenticate();

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            return;
        }

        $this->task->id = $id;

        if ($this->task->forceDelete()) {
            // Log Activity
            $this->activityLog->create($this->user_id, "Task Permanently Deleted", "Permanently deleted task ID: " . $id, $id);

            http_response_code(200);
            echo json_encode(array("message" => "Task was permanently deleted."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to permanently delete task."));
        }
    }


    public function extendDeadline($id)
    {
        $this->authenticate();

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->due_date)) {
            http_response_code(400);
            echo json_encode(array("message" => "Due date is required."));
            return;
        }

        $this->task->id = $id;

        if ($this->task->getById()) {
            $this->task->due_date = $data->due_date;
            $this->task->is_extended = 1;

            if ($this->task->update()) {
                // Log Activity
                $this->activityLog->create($this->user_id, "Task Deadline Extended", "Extended deadline for task: " . $this->task->title . " to " . $this->task->due_date, $this->task->id);

                http_response_code(200);
                echo json_encode(array("message" => "Deadline extended successfully."));
            } else {
                http_response_code(503);
                echo json_encode(array("message" => "Unable to extend deadline."));
            }
        } else {
            http_response_code(404);
            echo json_encode(array("message" => "Task not found."));
        }
    }

    public function getStats()
    {
        $this->authenticate();

        $stats = $this->task->getStatistics($this->user_id, $this->user_role, $this->company_id);

        http_response_code(200);
        echo json_encode($stats);
    }

    /* =======================
       EXECUTION WORKFLOW
     ======================= */
    public function updateStage($id)
    {
        $this->authenticate();
        $this->task->id = $id;

        if (!$this->task->getById()) {
            http_response_code(404);
            echo json_encode(["message" => "Task not found."]);
            return;
        }

        // PERMISSIONS:
        // - Assigned User: Can execute steps sequentially.
        // - Admin/Owner/Manager: Can OVERRIDE steps but MUST provide a reason.

        $isManager = in_array($this->user_role, ['admin', 'manager', 'owner']);
        $isAssignee = ($this->task->assigned_to == $this->user_id);

        if (!$isManager && !$isAssignee) {
            http_response_code(403);
            echo json_encode(["message" => "Access denied. You are not assigned to this task."]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"));
        $newStage = $data->stage ?? '';
        $note = $data->note ?? ''; // This acts as the "Reason" for managers

        if (empty($newStage)) {
            http_response_code(400);
            echo json_encode(["message" => "New stage required."]);
            return;
        }

        // ENFORCE OVERRIDE REASON
        if ($isManager && !$isAssignee) {
            if (empty($note) || trim($note) === '') {
                http_response_code(403);
                echo json_encode(["message" => "Management override requires a documented reason."]);
                return;
            }
            // Save to task model to persist in DB
            $this->task->last_override_reason = $note;
        } else {
            // Optional: Clear override reason if assignee is successful? 
            // Or keep history. Let's keep it until next override.
            // If we want to clear it when assignee acknowledges or moves forward:
            // $this->task->last_override_reason = null; 
        }

        $currentStage = $this->task->execution_stage;

        // Log Logic
        $logAction = ($isManager && !$isAssignee) ? "Stage Override" : "Stage Updated";
        $logMessage = "Moved from $currentStage to $newStage";
        if ($isManager && !$isAssignee) {
            $logMessage .= " [OVERRIDE REASON: $note]";
        }

        // TRANSITION LOGIC
        switch ($newStage) {
            case 'started':
                // Can start if not started
                if ($currentStage !== 'not_started' && !$isManager) {
                    $this->sendError("Task already started.");
                    return;
                }
                $this->task->execution_stage = 'started';
                $this->task->started_at = date('Y-m-d H:i:s');
                $this->task->status = 'in_progress'; // Auto-move status
                break;

            case 'local_done':
                // Must be started
                if ($currentStage !== 'started' && !$isManager) {
                    $this->sendError("Task must be started first.");
                    return;
                }
                $this->task->execution_stage = 'local_done';
                $this->task->local_run_at = date('Y-m-d H:i:s');
                // Status stays in_progress
                break;

            case 'live_done':
                // Must be local_done
                if ($currentStage !== 'local_done' && !$isManager) {
                    $this->sendError("Task must be locally tested first.");
                    return;
                }
                $this->task->execution_stage = 'live_done';
                $this->task->live_run_at = date('Y-m-d H:i:s');
                break;

            case 'review':
                // Special step: Move status to waiting_for_review. No stage change strictly, but implies flow done.
                if ($currentStage !== 'live_done' && !$isManager) {
                    $this->sendError("Task must be deployed live first.");
                    return;
                }
                $this->task->status = 'waiting_for_review';
                break;

            case 'completed':
                // Allow direct completion if live done
                if ($currentStage !== 'live_done' && $this->task->status !== 'waiting_for_review' && !$isManager) {
                    $this->sendError("Task cannot be completed yet.");
                    return;
                }
                $this->task->status = 'completed';
                $this->task->completed_at = date('Y-m-d H:i:s');
                break;

            // Allow reverting for managers (Backward compatibility with override)
            case 'not_started':
                if (!$isManager) {
                    $this->sendError("Only managers can reset tasks.");
                    return;
                }
                $this->task->execution_stage = 'not_started';
                $this->task->status = 'pending';
                break;

            default:
                $this->sendError("Invalid stage transition.");
                return;
        }

        if ($this->task->update()) {
            // Include note in validation logs for everyone if provided
            $finalLog = $logMessage . ($note && !($isManager && !$isAssignee) ? " Note: $note" : "");

            $this->activityLog->create($this->user_id, $logAction, $finalLog, $this->task->id);
            http_response_code(200);
            echo json_encode(["message" => "Stage updated successfully.", "stage" => $newStage]);
        } else {
            http_response_code(503);
            echo json_encode(["message" => "Unable to update stage."]);
        }
    }

    private function sendError($msg)
    {
        http_response_code(400);
        echo json_encode(["message" => $msg]);
    }
}
?>