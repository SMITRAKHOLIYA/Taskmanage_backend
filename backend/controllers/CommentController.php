<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../models/Comment.php';
include_once __DIR__ . '/../models/Attachment.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class CommentController
{
    private $db;
    private $comment;
    private $attachment;
    private $jwt;
    private $user_id;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->comment = new Comment($this->db);
        $this->attachment = new Attachment($this->db);
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

    public function getComments($taskId)
    {
        $this->authenticate();
        $stmt = $this->comment->getByTask($taskId);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($comments);
    }

    public function addComment($data)
    {
        $this->authenticate(); // Ensure user is authenticated

        $this->comment->task_id = $data->task_id ?? null; // task_id might be set in api.php injection or from data
        $this->comment->user_id = $this->user_id; // Use authenticated user
        $this->comment->content = $data->content;
        $this->comment->context_id = $data->context_id ?? null;

        if (!$this->comment->task_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Task ID is required']);
            return;
        }

        if ($this->comment->create()) {
            echo json_encode(['message' => 'Comment added']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Comment could not be added']);
        }
    }

    public function getAttachments($taskId)
    {
        $this->authenticate();
        $stmt = $this->attachment->getByTask($taskId);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($attachments);
    }

    public function uploadAttachment($taskId, $file)
    {
        $this->authenticate();
        $userId = $this->user_id;

        $targetDir = __DIR__ . "/../uploads/";
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $fileName = basename($file["name"]);
        $targetFilePath = $targetDir . time() . "_" . $fileName;
        $fileType = pathinfo($targetFilePath, PATHINFO_EXTENSION);

        // Allow certain file formats
        $allowTypes = array('jpg', 'png', 'jpeg', 'gif', 'pdf', 'doc', 'docx', 'txt');
        if (in_array(strtolower($fileType), $allowTypes)) {
            if (move_uploaded_file($file["tmp_name"], $targetFilePath)) {
                $this->attachment->task_id = $taskId;
                $this->attachment->user_id = $userId;
                $this->attachment->file_name = $fileName;
                $this->attachment->file_path = "/uploads/" . time() . "_" . $fileName;

                if ($this->attachment->create()) {
                    echo json_encode(['message' => 'File uploaded successfully', 'file_path' => $this->attachment->file_path]);
                } else {
                    http_response_code(500);
                    echo json_encode(['message' => 'Database error: File upload failed']);
                }
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Sorry, there was an error uploading your file.']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Sorry, only JPG, JPEG, PNG, GIF, PDF, DOC & TXT files are allowed.']);
        }
    }
}
?>