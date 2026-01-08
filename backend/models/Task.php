<?php
class Task
{
    private $conn;
    private $table_name = "tasks";

    public $id;
    public $title;
    public $description;
    public $priority;
    public $status;
    public $due_date;
    public $created_by;
    public $assigned_to;
    public $created_at;
    public $updated_at;
    public $is_extended; // New property
    public $project_id;
    public $parent_id;
    public $recurring_task_id;
    public $questions;
    public $assigned_user_name;
    public $creator_name;
    public $execution_stage;
    public $started_at;
    public $local_run_at;
    public $live_run_at;
    public $completed_at;
    public $requires_execution_workflow;
    public $company_id; // Added to fix lint warnings
    public $project_title; // Added for mapped fields
    public $parent_task_title; // Added for mapped fields // New property

    public $last_override_reason; // New property

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create()
    {
        // ADD company_id to query
        $query = "INSERT INTO " . $this->table_name . " SET 
                    title=:title, 
                    description=:description, 
                    priority=:priority, 
                    status=:status, 
                    due_date=:due_date, 
                    created_by=:created_by, 
                    assigned_to=:assigned_to, 
                    project_id=:project_id, 
                    parent_id=:parent_id, 
                    recurring_task_id=:recurring_task_id, 
                    questions=:questions, 
                    company_id=:company_id,
                    requires_execution_workflow=:requires_execution_workflow,
                    is_extended=0,
                    last_override_reason=NULL";

        $stmt = $this->conn->prepare($query);

        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->priority = htmlspecialchars(strip_tags($this->priority));
        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->due_date = !empty($this->due_date) ? htmlspecialchars(strip_tags($this->due_date)) : null;
        $this->created_by = htmlspecialchars(strip_tags($this->created_by));
        $this->assigned_to = htmlspecialchars(strip_tags($this->assigned_to));
        $this->project_id = !empty($this->project_id) ? htmlspecialchars(strip_tags($this->project_id)) : null;
        $this->parent_id = !empty($this->parent_id) ? htmlspecialchars(strip_tags($this->parent_id)) : null;
        $this->recurring_task_id = !empty($this->recurring_task_id) ? htmlspecialchars(strip_tags($this->recurring_task_id)) : null;
        $this->company_id = !empty($this->company_id) ? htmlspecialchars(strip_tags($this->company_id)) : null;
        $this->requires_execution_workflow = isset($this->requires_execution_workflow) ? (int) $this->requires_execution_workflow : 0;

        // questions is JSON
        if (is_array($this->questions) || is_object($this->questions)) {
            $this->questions = json_encode($this->questions);
        }

        $stmt->bindParam(":title", $this->title);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":priority", $this->priority);
        $stmt->bindParam(":status", $this->status);
        if (empty($this->due_date)) {
            $this->due_date = null;
        }
        $stmt->bindParam(":due_date", $this->due_date);
        $stmt->bindParam(":created_by", $this->created_by);
        $stmt->bindParam(":assigned_to", $this->assigned_to);
        $stmt->bindParam(":project_id", $this->project_id);
        $stmt->bindParam(":parent_id", $this->parent_id);
        $stmt->bindParam(":recurring_task_id", $this->recurring_task_id);
        $stmt->bindParam(":requires_execution_workflow", $this->requires_execution_workflow);

        if ($this->company_id === null) {
            $stmt->bindValue(":company_id", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":company_id", $this->company_id, PDO::PARAM_INT);
        }

        $stmt->bindParam(":questions", $this->questions);

        if ($stmt->execute()) {
            // Capture new ID for downstream use (notifications, etc.)
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        // Log the error for debugging
        $error = $stmt->errorInfo();
        error_log("Task Creation Error: " . print_r($error, true));
        return false;
    }

    /**
     * Fetch a single task by ID, populate model properties, and return the row.
     */
    public function getById()
    {
        $query = "SELECT t.*, u.username as assigned_user_name, c.username as creator_name,
                         p.title as project_title, parent.title as parent_task_title
                  FROM " . $this->table_name . " t
                  LEFT JOIN users u ON t.assigned_to = u.id
                  LEFT JOIN users c ON t.created_by = c.id
                  LEFT JOIN projects p ON t.project_id = p.id
                  LEFT JOIN tasks parent ON t.parent_id = parent.id
                  WHERE t.id = :id
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $this->id = htmlspecialchars(strip_tags($this->id));
        $stmt->bindParam(":id", $this->id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $this->title = $row['title'];
            $this->description = $row['description'];
            $this->priority = $row['priority'];
            $this->status = $row['status'];
            $this->due_date = $row['due_date'];
            $this->created_by = $row['created_by'];
            $this->assigned_to = $row['assigned_to'];
            $this->created_at = $row['created_at'];
            $this->updated_at = $row['updated_at'];
            $this->is_extended = $row['is_extended']; // Populate is_extended
            $this->project_id = $row['project_id'];
            $this->project_title = $row['project_title']; // Mapped project title
            $this->parent_id = $row['parent_id'];
            $this->parent_task_title = $row['parent_task_title']; // Mapped parent task title
            $this->recurring_task_id = $row['recurring_task_id'];
            $this->questions = $row['questions'];
            // keep extra joined fields available to controller if needed
            $this->assigned_user_name = $row['assigned_user_name'];
            $this->creator_name = $row['creator_name'];
            $this->execution_stage = $row['execution_stage'];
            $this->started_at = $row['started_at'];
            $this->local_run_at = $row['local_run_at'];
            $this->live_run_at = $row['live_run_at'];
            $this->completed_at = $row['completed_at'];
            $this->requires_execution_workflow = $row['requires_execution_workflow'];
            $this->last_override_reason = $row['last_override_reason']; // Populate last_override_reason
            return $row;
        }
        return false;
    }

    /**
     * Update task fields.
     */
    public function update()
    {
        $query = "UPDATE " . $this->table_name . "
                  SET title = :title,
                      description = :description,
                      priority = :priority,
                      status = :status,
                      due_date = :due_date,
                      assigned_to = :assigned_to,
                      is_extended = :is_extended,
                      project_id = :project_id,
                      parent_id = :parent_id,
                      questions = :questions,
                      execution_stage = :execution_stage,
                      started_at = :started_at,
                      local_run_at = :local_run_at,
                      live_run_at = :live_run_at,
                      completed_at = :completed_at,
                      requires_execution_workflow = :requires_execution_workflow,
                      last_override_reason = :last_override_reason
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        $this->id = htmlspecialchars(strip_tags($this->id));
        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->priority = htmlspecialchars(strip_tags($this->priority));
        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->due_date = htmlspecialchars(strip_tags($this->due_date));
        $this->assigned_to = htmlspecialchars(strip_tags($this->assigned_to));
        $this->is_extended = isset($this->is_extended) ? (int) $this->is_extended : 0;
        $this->project_id = !empty($this->project_id) ? htmlspecialchars(strip_tags($this->project_id)) : null;
        $this->parent_id = !empty($this->parent_id) ? htmlspecialchars(strip_tags($this->parent_id)) : null;
        $this->requires_execution_workflow = isset($this->requires_execution_workflow) ? (int) $this->requires_execution_workflow : 0;

        // Ensure last_override_reason is handled properly (null if not set)
        $this->last_override_reason = !empty($this->last_override_reason) ? htmlspecialchars(strip_tags($this->last_override_reason)) : null;

        // questions is JSON
        if (is_array($this->questions) || is_object($this->questions)) {
            $this->questions = json_encode($this->questions);
        }

        $stmt->bindParam(":id", $this->id);
        $stmt->bindParam(":title", $this->title);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":priority", $this->priority);
        $stmt->bindParam(":status", $this->status);
        if (empty($this->due_date)) {
            $this->due_date = null;
        }
        $stmt->bindParam(":due_date", $this->due_date);
        $stmt->bindParam(":assigned_to", $this->assigned_to);
        $stmt->bindParam(":is_extended", $this->is_extended);
        $stmt->bindParam(":project_id", $this->project_id);
        $stmt->bindParam(":parent_id", $this->parent_id);
        $stmt->bindParam(":questions", $this->questions);
        $stmt->bindParam(":execution_stage", $this->execution_stage);
        $stmt->bindParam(":started_at", $this->started_at);
        $stmt->bindParam(":local_run_at", $this->local_run_at);
        $stmt->bindParam(":live_run_at", $this->live_run_at);
        $stmt->bindParam(":completed_at", $this->completed_at);
        $stmt->bindParam(":requires_execution_workflow", $this->requires_execution_workflow);
        $stmt->bindParam(":last_override_reason", $this->last_override_reason);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function getAll($user_id, $role, $params = [], $company_id = null)
    {
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $limit = isset($params['limit']) ? (int) $params['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $sort_by = isset($params['sort_by']) ? $params['sort_by'] : 'created_at';
        $sort_order = isset($params['sort_order']) && strtoupper($params['sort_order']) === 'ASC' ? 'ASC' : 'DESC';

        $search = isset($params['search']) ? $params['search'] : '';
        $priority = isset($params['priority']) ? $params['priority'] : '';
        $status = isset($params['status']) ? $params['status'] : '';
        $project_id = isset($params['project_id']) ? $params['project_id'] : '';
        $parent_id = isset($params['parent_id']) ? $params['parent_id'] : '';
        $exclude_status = isset($params['exclude_status']) ? $params['exclude_status'] : '';

        // Allowed sort columns
        $allowed_sorts = ['title', 'due_date', 'priority', 'created_at', 'assigned_to'];
        if (!in_array($sort_by, $allowed_sorts)) {
            $sort_by = 'created_at';
        }

        // Base Query - Filter out deleted tasks
        $query = "SELECT t.*, u.username as assigned_user_name, c.username as creator_name 
                  FROM " . $this->table_name . " t 
                  LEFT JOIN users u ON t.assigned_to = u.id 
                  LEFT JOIN users c ON t.created_by = c.id
                  WHERE t.deleted_at IS NULL";

        // Role Restriction
        // Unified Company Isolation: Everyone sees only their company's tasks
        if ($company_id) {
            $query .= " AND t.company_id = :company_id";
        }

        // Additional Role Restrictions
        if ($role !== 'owner' && $role !== 'admin' && $role !== 'manager') {
            $query .= " AND (t.assigned_to = :user_id OR t.created_by = :user_id)";
        }

        // Filters
        if (!empty($search)) {
            $query .= " AND (t.title LIKE :search OR t.description LIKE :search)";
        }
        if (!empty($priority)) {
            $query .= " AND t.priority = :priority";
        }
        if (!empty($status) && $status !== 'all') {
            $query .= " AND t.status = :status";
        }
        if (!empty($project_id)) {
            $query .= " AND t.project_id = :project_id";
        }
        if (isset($params['parent_id']) && $params['parent_id'] !== '') {
            if ($parent_id === 'null') {
                $query .= " AND t.parent_id IS NULL";
            } else {
                $query .= " AND t.parent_id = :parent_id";
            }
        }
        if (!empty($exclude_status)) {
            $query .= " AND t.status != :exclude_status";
        }

        // Sorting
        if ($sort_by === 'assigned_to') {
            $query .= " ORDER BY u.username $sort_order";
        } else {
            $query .= " ORDER BY t.$sort_by $sort_order";
        }

        // Pagination
        $query .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);

        // Bind Parameters
        // Bind Parameters
        if ($company_id) {
            $stmt->bindParam(":company_id", $company_id);
        }

        if ($role !== 'owner' && $role !== 'admin' && $role !== 'manager') {
            $stmt->bindParam(":user_id", $user_id);
        }

        if (!empty($search)) {
            $search_term = "%{$search}%";
            $stmt->bindParam(":search", $search_term);
        }
        if (!empty($priority)) {
            $stmt->bindParam(":priority", $priority);
        }
        if (!empty($status) && $status !== 'all') {
            $stmt->bindParam(":status", $status);
        }
        if (!empty($project_id)) {
            $stmt->bindParam(":project_id", $project_id);
        }
        if (isset($params['parent_id']) && $params['parent_id'] !== '' && $parent_id !== 'null') {
            $stmt->bindParam(":parent_id", $parent_id);
        }
        if (!empty($exclude_status)) {
            $stmt->bindParam(":exclude_status", $exclude_status);
        }

        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt;
    }

    public function countAll($user_id, $role, $params = [], $company_id = null)
    {
        $search = isset($params['search']) ? $params['search'] : '';
        $priority = isset($params['priority']) ? $params['priority'] : '';
        $status = isset($params['status']) ? $params['status'] : '';
        $project_id = isset($params['project_id']) ? $params['project_id'] : '';
        $parent_id = isset($params['parent_id']) ? $params['parent_id'] : '';
        $exclude_status = isset($params['exclude_status']) ? $params['exclude_status'] : '';

        // Base query with joins for filtering
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name . " t 
                  LEFT JOIN users c ON t.created_by = c.id
                  WHERE t.deleted_at IS NULL";

        // Unified Company Isolation
        if ($company_id) {
            $query .= " AND t.company_id = :company_id";
        }

        if ($role !== 'owner' && $role !== 'admin' && $role !== 'manager') {
            $query .= " AND (t.assigned_to = :user_id OR t.created_by = :user_id)";
        }

        if (!empty($search)) {
            $query .= " AND (t.title LIKE :search OR t.description LIKE :search)";
        }
        if (!empty($priority)) {
            $query .= " AND t.priority = :priority";
        }
        if (!empty($status) && $status !== 'all') {
            $query .= " AND t.status = :status";
        }
        if (!empty($project_id)) {
            $query .= " AND t.project_id = :project_id";
        }
        if (isset($params['parent_id']) && $params['parent_id'] !== '') {
            if ($parent_id === 'null') {
                $query .= " AND t.parent_id IS NULL";
            } else {
                $query .= " AND t.parent_id = :parent_id";
            }
        }
        if (!empty($exclude_status)) {
            $query .= " AND t.status != :exclude_status";
        }

        $stmt = $this->conn->prepare($query);

        if ($company_id) {
            $stmt->bindParam(":company_id", $company_id);
        }

        if ($role !== 'owner' && $role !== 'admin' && $role !== 'manager') {
            $stmt->bindParam(":user_id", $user_id);
        }

        if (!empty($search)) {
            $search_term = "%{$search}%";
            $stmt->bindParam(":search", $search_term);
        }
        if (!empty($priority)) {
            $stmt->bindParam(":priority", $priority);
        }
        if (!empty($status) && $status !== 'all') {
            $stmt->bindParam(":status", $status);
        }
        if (!empty($project_id)) {
            $stmt->bindParam(":project_id", $project_id);
        }
        if (isset($params['parent_id']) && $params['parent_id'] !== '' && $parent_id !== 'null') {
            $stmt->bindParam(":parent_id", $parent_id);
        }
        if (!empty($exclude_status)) {
            $stmt->bindParam(":exclude_status", $exclude_status);
        }

        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'];
    }

    // Soft Delete
    public function delete()
    {
        $query = "UPDATE " . $this->table_name . " SET deleted_at = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $this->id = htmlspecialchars(strip_tags($this->id));
        $stmt->bindParam(1, $this->id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Get Trash
    public function getTrash($user_id, $role, $company_id = null)
    {
        $query = "SELECT t.*, u.username as assigned_user_name, c.username as creator_name 
                  FROM " . $this->table_name . " t 
                  LEFT JOIN users u ON t.assigned_to = u.id 
                  LEFT JOIN users c ON t.created_by = c.id
                  WHERE t.deleted_at IS NOT NULL";

        // Unified Company Isolation
        if ($company_id) {
            $query .= " AND t.company_id = :company_id";
        }

        if ($role !== 'owner' && $role !== 'admin' && $role !== 'manager') {
            $query .= " AND (t.assigned_to = :user_id OR t.created_by = :user_id)";
        }

        $query .= " ORDER BY t.deleted_at DESC";

        $stmt = $this->conn->prepare($query);

        if ($company_id) {
            $stmt->bindParam(":company_id", $company_id);
        }

        if ($role !== 'owner' && $role !== 'admin' && $role !== 'manager') {
            $stmt->bindParam(":user_id", $user_id);
        }

        $stmt->execute();
        return $stmt;
    }

    // Restore Task
    public function restore()
    {
        $query = "UPDATE " . $this->table_name . " SET deleted_at = NULL WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $this->id = htmlspecialchars(strip_tags($this->id));
        $stmt->bindParam(1, $this->id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Force Delete
    public function forceDelete()
    {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $this->id = htmlspecialchars(strip_tags($this->id));
        $stmt->bindParam(1, $this->id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    // Get Statistics (Total, Pending, In Progress, Completed)
    public function getStatistics($user_id, $role, $company_id = null)
    {
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                  FROM " . $this->table_name . " t 
                  LEFT JOIN users c ON t.created_by = c.id
                  WHERE t.deleted_at IS NULL";

        // Unified Company Isolation
        if ($company_id) {
            $query .= " AND t.company_id = :company_id";
        }

        if ($role !== 'owner' && $role !== 'admin' && $role !== 'manager') {
            $query .= " AND (t.assigned_to = :user_id OR t.created_by = :user_id)";
        }

        $stmt = $this->conn->prepare($query);

        if ($company_id) {
            $stmt->bindParam(":company_id", $company_id);
        }

        if ($role !== 'owner' && $role !== 'admin' && $role !== 'manager') {
            $stmt->bindParam(":user_id", $user_id);
        }

        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Ensure we return zeros instead of nulls if no tasks found
        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'in_progress' => (int) ($row['in_progress'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0)
        ];
    }
}
?>