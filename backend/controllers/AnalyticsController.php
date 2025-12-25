<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../models/User.php';
include_once __DIR__ . '/../models/Task.php';
include_once __DIR__ . '/../models/ActivityLog.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class AnalyticsController
{
    private $db;
    private $user;
    private $task;
    private $activityLog;
    private $jwt;
    private $user_id;
    private $user_role;
    private $company_id;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->user = new User($this->db);
        $this->task = new Task($this->db);
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

    public function calculateScores($target_user_id)
    {
        // 1. Time Discipline Analysis
        // Track tasks completed before deadline, on time, late, or expired
        $query = "SELECT * FROM tasks WHERE assigned_to = :user_id AND deleted_at IS NULL";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $target_user_id);
        $stmt->execute();
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_completed = 0;
        $on_time = 0;
        $late = 0;
        $expired = 0;

        foreach ($tasks as $task) {
            if ($task['status'] === 'completed') {
                $total_completed++;
                // Assuming updated_at is the completion time for simplicity, or we check activity logs
                // For now, let's use updated_at if status is completed
                $completion_time = new DateTime($task['updated_at']);
                $due_date = $task['due_date'] ? new DateTime($task['due_date']) : null;

                if ($due_date) {
                    if ($completion_time <= $due_date) {
                        $on_time++;
                    } else {
                        $late++;
                    }
                } else {
                    $on_time++; // No deadline = on time
                }
            } elseif ($task['status'] === 'expired') {
                $expired++;
            }
        }

        $total_tasks_considered = $total_completed + $expired;
        $time_discipline_score = 0;
        if ($total_tasks_considered > 0) {
            // Formula: (On Time / Total) * 100 - Penalties
            $base_score = ($on_time / $total_tasks_considered) * 100;
            // Penalty for expired: -5 per expired task (capped at 0)
            // Penalty for late: -2 per late task
            // This is a simplified scoring model
            $time_discipline_score = $base_score;
        } else {
            $time_discipline_score = 100; // Default if no tasks
        }


        // 2. Consistency & Habit Tracking
        // Track daily activity streaks
        $query = "SELECT DATE(created_at) as activity_date FROM activity_logs WHERE user_id = :user_id GROUP BY DATE(created_at) ORDER BY activity_date DESC LIMIT 30";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $target_user_id);
        $stmt->execute();
        $activity_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $consistency_score = 0;
        if (count($activity_dates) > 0) {
            // Simple consistency: (Active Days in last 30 days / 30) * 100
            // Or calculate streaks. Let's use active days ratio for robustness.
            $consistency_score = (count($activity_dates) / 30) * 100;
            if ($consistency_score > 100)
                $consistency_score = 100;
        }

        // 3. Responsibility Measurement
        // Measure how fast users accept assigned tasks (Assignment -> In Progress)
        $query = "SELECT t.id, t.created_at, 
                  (SELECT created_at FROM activity_logs WHERE task_id = t.id AND details LIKE '%Status changed from pending to in_progress%' ORDER BY created_at ASC LIMIT 1) as start_time
                  FROM tasks t WHERE t.assigned_to = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $target_user_id);
        $stmt->execute();
        $responsibility_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_response_time_hours = 0;
        $response_count = 0;

        foreach ($responsibility_tasks as $rt) {
            if ($rt['start_time']) {
                $created = new DateTime($rt['created_at']);
                $started = new DateTime($rt['start_time']);
                $interval = $created->diff($started);
                $hours = $interval->h + ($interval->days * 24);
                $total_response_time_hours += $hours;
                $response_count++;
            }
        }

        $responsibility_score = 100;
        if ($response_count > 0) {
            $avg_response_time = $total_response_time_hours / $response_count;
            // Scoring: < 24h = 100, < 48h = 80, < 72h = 60, else 40
            if ($avg_response_time <= 24)
                $responsibility_score = 100;
            elseif ($avg_response_time <= 48)
                $responsibility_score = 80;
            elseif ($avg_response_time <= 72)
                $responsibility_score = 60;
            else
                $responsibility_score = 40;
        }

        // 4. Pressure Handling Intelligence
        // Analyze performance during high workload periods (e.g., > 5 tasks due in a week)
        // Simplified: Just use a placeholder or a basic metric like "Tasks Completed / Tasks Assigned" ratio
        $total_assigned = count($tasks);
        $pressure_handling_score = 100;
        if ($total_assigned > 0) {
            $pressure_handling_score = ($total_completed / $total_assigned) * 100;
        }

        // 5. Reliability Index (Main Score)
        // Weighted Average
        $reliability_index = ($time_discipline_score * 0.4) + ($consistency_score * 0.3) + ($responsibility_score * 0.2) + ($pressure_handling_score * 0.1);

        // Update Database
        $query = "INSERT INTO user_reliability_scores (user_id, reliability_index, time_discipline_score, consistency_score, responsibility_score, pressure_handling_score)
                  VALUES (:user_id, :ri, :td, :cs, :rs, :ph)
                  ON DUPLICATE KEY UPDATE
                  reliability_index = :ri, time_discipline_score = :td, consistency_score = :cs, responsibility_score = :rs, pressure_handling_score = :ph";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $target_user_id);
        $stmt->bindParam(':ri', $reliability_index);
        $stmt->bindParam(':td', $time_discipline_score);
        $stmt->bindParam(':cs', $consistency_score);
        $stmt->bindParam(':rs', $responsibility_score);
        $stmt->bindParam(':ph', $pressure_handling_score);
        $stmt->execute();

        return [
            'reliability_index' => round($reliability_index, 1),
            'time_discipline_score' => round($time_discipline_score, 1),
            'consistency_score' => round($consistency_score, 1),
            'responsibility_score' => round($responsibility_score, 1),
            'pressure_handling_score' => round($pressure_handling_score, 1)
        ];
    }

    public function getUserReliabilityReport($user_id = null)
    {
        $this->authenticate();
        $target_user_id = $user_id ?? $this->user_id;
        $scores = $this->calculateScores($target_user_id);
        http_response_code(200);
        echo json_encode($scores);
    }

    public function getUserRankings()
    {
        $this->authenticate();
        $query = "SELECT id, username FROM users WHERE company_id = :company_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':company_id', $this->company_id);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rankings = [];
        foreach ($users as $user) {
            $scores = $this->calculateScores($user['id']);
            $rankings[] = array_merge($user, $scores);
        }

        usort($rankings, function ($a, $b) {
            return $b['reliability_index'] <=> $a['reliability_index'];
        });

        http_response_code(200);
        echo json_encode($rankings);
    }

    public function getOverallStats()
    {
        $this->authenticate();
        $query = "SELECT status, COUNT(*) as count FROM tasks t 
                  JOIN users u ON t.assigned_to = u.id 
                  WHERE u.company_id = :company_id AND t.deleted_at IS NULL 
                  GROUP BY status";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':company_id', $this->company_id);
        $stmt->execute();
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode($stats);
    }

    public function getAdminReport()
    {
        $this->authenticate();

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            return;
        }

        // Pagination parameters
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        // Get total users count for company
        $count_query = "SELECT COUNT(*) as total FROM users WHERE company_id = :company_id";
        $stmt = $this->db->prepare($count_query);
        $stmt->bindParam(':company_id', $this->company_id);
        $stmt->execute();
        $total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_users / $limit);

        // Fetch users for current page and company
        $query = "SELECT id, username FROM users WHERE company_id = :company_id LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':company_id', $this->company_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $report_data = [];
        foreach ($users as $user) {
            $scores = $this->calculateScores($user['id']);
            $report_data[] = array_merge($user, $scores);
        }

        usort($report_data, function ($a, $b) {
            return $b['reliability_index'] <=> $a['reliability_index'];
        });

        http_response_code(200);
        echo json_encode([
            'data' => $report_data,
            'meta' => [
                'total_items' => $total_users,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'limit' => $limit
            ]
        ]);
    }
}
?>