<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class SyncController
{
    private $db;
    private $jwt;
    private $user_id;
    private $user_role;
    private $company_id;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
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
        // Assuming company_id is available in token or we fetch it. 
        // For now, let's fetch it from DB to be safe and ensure isolation.

        $query = "SELECT company_id FROM users WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $this->user_id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && isset($row['company_id'])) {
            $this->company_id = $row['company_id'];
        } else {
            $this->company_id = null;
            // Optionally log or handle user without company
        }

        return true;
    }

    public function getSyncStatus()
    {
        $this->authenticate();

        try {
            // Check for latest tasks update within the company
            // Start basic: MAX updated_at. Simple and effective for small-medium scale.
            // Note: Tasks created_at is also important if no updates happen.
            // Let's use GREATEST(MAX(updated_at), MAX(created_at)) logic implicitly by checking both.

            $queryTasks = "SELECT 
                            MAX(updated_at) as last_update, 
                            MAX(created_at) as last_create,
                            COUNT(id) as total_count 
                           FROM tasks 
                           WHERE company_id = :company_id AND deleted_at IS NULL";

            $stmtTasks = $this->db->prepare($queryTasks);
            $stmtTasks->bindParam(":company_id", $this->company_id);
            $stmtTasks->execute();
            $taskData = $stmtTasks->fetch(PDO::FETCH_ASSOC) ?: ['last_update' => null, 'last_create' => null, 'total_count' => 0];

            // Calculate a single hash/timestamp for tasks
            $taskTimestamp = max(
                strtotime($taskData['last_update'] ?? '1970-01-01'),
                strtotime($taskData['last_create'] ?? '1970-01-01')
            );

            // Check Notifications for this SPECIFIC user
            $queryNotifs = "SELECT COUNT(*) as count, MAX(created_at) as last_notif FROM notifications WHERE user_id = :user_id AND is_read = 0";
            $stmtNotifs = $this->db->prepare($queryNotifs);
            $stmtNotifs->bindParam(":user_id", $this->user_id);
            $stmtNotifs->execute();
            $notifData = $stmtNotifs->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'last_notif' => null];

            $response = [
                'tasks' => [
                    'last_updated' => $taskTimestamp,
                    'total_count' => $taskData['total_count']
                ],
                'notifications' => [
                    'unread_count' => $notifData['count'],
                    'last_created' => strtotime($notifData['last_notif'] ?? '1970-01-01')
                ],
                'timestamp' => time() // Server time for reference
            ];

            http_response_code(200);
            echo json_encode($response);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(array("message" => "Sync check failed.", "error" => $e->getMessage()));
        }
    }
}
?>