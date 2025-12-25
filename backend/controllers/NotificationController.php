<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class NotificationController
{
    private $db;
    private $jwt;
    private $user_id;

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
        return true;
    }

    public function getUserNotifications()
    {
        $this->authenticate();

        $query = "SELECT n.*, t.title as task_title 
                  FROM notifications n 
                  LEFT JOIN tasks t ON n.task_id = t.id 
                  WHERE n.user_id = :user_id 
                  ORDER BY n.created_at DESC LIMIT 100";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":user_id", $this->user_id);
        $stmt->execute();

        $notifications = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            array_push($notifications, $row);
        }

        http_response_code(200);
        echo json_encode($notifications);
    }

    public function markAsRead($id)
    {
        $this->authenticate();

        $query = "UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":user_id", $this->user_id);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(array("message" => "Notification marked as read."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to update notification."));
        }
    }

    public function delete($id)
    {
        $this->authenticate();

        $query = "DELETE FROM notifications WHERE id = :id AND user_id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":user_id", $this->user_id);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(array("message" => "Notification deleted."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to delete notification."));
        }
    }

    public function deleteAll()
    {
        $this->authenticate();

        $query = "DELETE FROM notifications WHERE user_id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":user_id", $this->user_id);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(array("message" => "All notifications deleted."));
        } else {
            http_response_code(503);
            echo json_encode(array("message" => "Unable to delete notifications."));
        }
    }

    // Internal function to create notification
    public function createNotification($user_id, $task_id, $message, $type)
    {
        // Avoid duplicate logic if needed, but 'assignment' should be unique per task assigned?
        // Rely on caller or check here.
        // For 'assignment', duplicate check might be overkill if controller logic is sound.
        // For 'reminder', we MUST check.

        if ($type === 'reminder' || $type === 'overdue') {
            // Check for duplicate message for same task today (or recently)
            // Ideally avoid flooding.
            // Check if exact same message exists for this user and task
            $checkQuery = "SELECT id FROM notifications 
                           WHERE user_id = :user_id AND task_id = :task_id AND message = :message AND is_read = 0";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->bindParam(":user_id", $user_id);
            $checkStmt->bindParam(":task_id", $task_id);
            $checkStmt->bindParam(":message", $message);
            $checkStmt->execute();
            if ($checkStmt->rowCount() > 0) {
                return; // Duplicate found, skip
            }
        }

        $query = "INSERT INTO notifications (user_id, task_id, message, type) 
                  VALUES (:user_id, :task_id, :message, :type)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":task_id", $task_id);
        $stmt->bindParam(":message", $message);
        $stmt->bindParam(":type", $type);
        $stmt->execute();
    }

    public function checkReminders()
    {
        // This endpoint can be called by cron. Authentication is required.
        $this->authenticate();

        try {
            $today = date('Y-m-d');

            // 1. Task is still pending (check due date)
            $query = "SELECT t.id, t.title, t.assigned_to, t.due_date 
                    FROM tasks t 
                    WHERE DATE(t.due_date) = :today AND t.status != 'completed'";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(":today", $today);
            $stmt->execute();

            $currentHour = (int) date('H');

            while ($task = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($currentHour >= 22) { // 10 PM or later
                    $msg = "Reminder: Task '{$task['title']}' is due today! Please complete it before midnight.";
                    // Check duplicate for reminder specifically
                    $this->createNotification($task['assigned_to'], $task['id'], $msg, 'reminder');
                }
            }

            // 2. Task is overdue (daily reminder)
            $queryOverdue = "SELECT t.id, t.title, t.assigned_to, t.due_date 
                            FROM tasks t 
                            WHERE DATE(t.due_date) < :today AND t.status != 'completed'";

            $stmtOverdue = $this->db->prepare($queryOverdue);
            $stmtOverdue->bindParam(":today", $today);
            $stmtOverdue->execute();

            while ($task = $stmtOverdue->fetch(PDO::FETCH_ASSOC)) {
                $msg = "Overdue: Task '{$task['title']}' is overdue! Please complete it ASAP.";

                // Check if we sent this message *today*
                $checkQuery = "SELECT id FROM notifications 
                            WHERE user_id = :uid AND task_id = :tid 
                            AND type = 'overdue' AND DATE(created_at) = :today";
                $checkStmt = $this->db->prepare($checkQuery);
                $checkStmt->bindParam(":uid", $task['assigned_to']);
                $checkStmt->bindParam(":tid", $task['id']);
                $checkStmt->bindParam(":today", $today);
                $checkStmt->execute();

                if ($checkStmt->rowCount() == 0) {
                    $this->createNotification(
                        $task['assigned_to'],
                        $task['id'],
                        $msg,
                        'overdue'
                    );
                }
            }

            http_response_code(200);
            echo json_encode(array("message" => "Reminders processed."));

        } catch (Exception $e) {
            // Log error but don't fail the request significantly for the user
            error_log("Check Reminders Error: " . $e->getMessage());
            http_response_code(200); // Return 200 to prevent frontend console errors, but with warning
            echo json_encode(array("message" => "Reminder check completed with warnings.", "error" => $e->getMessage()));
        }
    }
}
?>