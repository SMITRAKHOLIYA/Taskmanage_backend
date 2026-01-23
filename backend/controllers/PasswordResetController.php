<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/EmailService.php';

class PasswordResetController
{
    private $db;
    private $user;
    private $emailService;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->user = new User($this->db);
        $this->emailService = new EmailService();
    }

    public function requestReset()
    {
        $data = json_decode(file_get_contents("php://input"));
        $email = $data->email ?? '';

        if (empty($email)) {
            http_response_code(400);
            echo json_encode(["message" => "Email is required"]);
            return;
        }

        // --- BYPASS User Model for Email Check (Redundant but safe) ---
        $this->user->email = $email;
        if (!$this->user->emailExists()) {
            http_response_code(200);
            echo json_encode(["message" => "If an account with this email exists, a reset link has been sent."]);
            return;
        }

        // --- DIRECT SQL: Check Rate Limit ---
        $query = "SELECT last_reset_request_at FROM users WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $this->user->id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['last_reset_request_at']) {
            $lastRequest = strtotime($row['last_reset_request_at']);
            $diff = time() - $lastRequest;
            if ($diff < 120) { // 2 minutes
                http_response_code(429);
                echo json_encode(["message" => "Please wait " . (120 - $diff) . " seconds before requesting another email."]);
                return;
            }
        }

        // Generate Token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // --- DIRECT SQL: Save Token (Replacing User::saveResetToken) ---
        $updateQuery = "UPDATE users 
                        SET reset_token_hash = :hash, 
                            reset_token_expires_at = :expires, 
                            last_reset_request_at = NOW() 
                        WHERE id = :id";
        $updateStmt = $this->db->prepare($updateQuery);
        $updateStmt->bindParam(':hash', $tokenHash);
        $updateStmt->bindParam(':expires', $expiresAt);
        $updateStmt->bindParam(':id', $this->user->id);

        if ($updateStmt->execute()) {
            // Send Email
            $emailResult = $this->emailService->sendPasswordResetEmail($this->user->email, $token);
            if ($emailResult['success']) {
                http_response_code(200);
                echo json_encode(["message" => "If an account with this email exists, a reset link has been sent."]);
            } else {
                // Log the specific email error
                error_log("Email sending failed for {$this->user->email}: " . $emailResult['error']);

                http_response_code(500);
                echo json_encode(["message" => "Failed to send email. Please try again later."]);
            }
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Something went wrong."]);
        }
    }

    public function resetPassword()
    {
        $data = json_decode(file_get_contents("php://input"));
        $token = $data->token ?? '';
        $newPassword = $data->password ?? '';

        if (empty($token) || empty($newPassword)) {
            http_response_code(400);
            echo json_encode(["message" => "Token and new password are required"]);
            return;
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $newPassword)) {
            http_response_code(400);
            echo json_encode(["message" => "Password must be at least 8 characters long and include: uppercase, lowercase, number, and special character."]);
            return;
        }

        $tokenHash = hash('sha256', $token);

        // --- DIRECT SQL: Get By Reset Token (Replacing User::getByResetToken) ---
        $query = "SELECT id, reset_token_expires_at FROM users 
                  WHERE reset_token_hash = :hash LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':hash', $tokenHash);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Check expiry
            if (strtotime($row['reset_token_expires_at']) > time()) {
                $userId = $row['id'];
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                // --- DIRECT SQL: Update Password (Replacing User::updatePassword) ---
                $updateQuery = "UPDATE users 
                                SET password_hash = :password, 
                                    reset_token_hash = NULL, 
                                    reset_token_expires_at = NULL 
                                WHERE id = :id";
                $updateStmt = $this->db->prepare($updateQuery);
                $updateStmt->bindParam(':password', $newPasswordHash);
                $updateStmt->bindParam(':id', $userId);

                if ($updateStmt->execute()) {
                    http_response_code(200);
                    echo json_encode(["message" => "Password has been reset successfully. You can now login."]);
                } else {
                    http_response_code(500);
                    echo json_encode(["message" => "Failed to update password."]);
                }
            } else {
                http_response_code(400);
                echo json_encode(["message" => "Invalid or expired token."]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["message" => "Invalid or expired token."]);
        }
    }
}
?>