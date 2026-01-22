<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../vendor/autoload.php'; // Ensure PHPMailer is loaded
require_once __DIR__ . '/../utils/JWTHandler.php';
require_once __DIR__ . '/../config/mail.php';

class InviteController
{
    private $db;
    private $conn;
    private $userModel;
    private $jwt;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->userModel = new User($this->conn);
        $this->jwt = new JWTHandler();
    }

    // Helper to get authenticated user from header
    // Helper to get authenticated user from header
    private function getAuthUser()
    {
        $headers = null;
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        } else {
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (substr($key, 0, 5) <> 'HTTP_') {
                    continue;
                }
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
        }

        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (!$authHeader)
            return null;

        $token = str_replace('Bearer ', '', $authHeader);
        return $this->jwt->validate_jwt($token); // Returns object or null
    }

    public function invite()
    {
        $currentUser = $this->getAuthUser();
        if (!$currentUser) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"));
        $email = $data->email ?? '';
        $role = $data->role ?? 'user';

        if (empty($email) || empty($role)) {
            http_response_code(400);
            echo json_encode(["message" => "Email and role are required"]);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid email address format"]);
            return;
        }

        // --- Role Based Access Control ---
        $allowed = false;
        if ($currentUser->role === 'owner') {
            if (in_array($role, ['admin', 'manager', 'user']))
                $allowed = true;
        } elseif ($currentUser->role === 'admin') {
            if ($role === 'user')
                $allowed = true;
        } elseif ($currentUser->role === 'manager') {
            if ($role === 'user')
                $allowed = true;
        }

        if (!$allowed) {
            http_response_code(403);
            echo json_encode(["message" => "You ensure permissions to invite this role."]);
            return;
        }

        // --- Check if user already exists ---
        $this->userModel->email = $email;
        if ($this->userModel->emailExists()) {
            http_response_code(400);
            echo json_encode(["message" => "User with this email already exists."]);
            return;
        }

        // --- Check for existing pending invitation ---
        $checkStmt = $this->conn->prepare("SELECT id FROM user_invitations WHERE email = :email AND status = 'pending'");
        $checkStmt->bindParam(':email', $email);
        $checkStmt->execute();
        if ($checkStmt->rowCount() > 0) {
            // Option: Resend invite? For now, error.
            http_response_code(400);
            echo json_encode(["message" => "Invitation already pending for this email."]);
            return;
        }

        // --- Create Invitation ---
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));

        $query = "INSERT INTO user_invitations (email, role, token, inviter_id, company_id, status, expires_at) 
                  VALUES (:email, :role, :token, :inviter_id, :company_id, 'pending', :expires_at)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':inviter_id', $currentUser->id);
        $stmt->bindParam(':company_id', $currentUser->company_id);
        $stmt->bindParam(':expires_at', $expiresAt);

        if ($stmt->execute()) {
            // --- Send Email ---
            $emailResult = $this->sendInviteEmail($email, $token, $role);
            if ($emailResult['success']) {
                http_response_code(201);
                echo json_encode(["message" => "Invitation sent successfully."]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Invitation created but email failed: " . $emailResult['error']]);
            }
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Failed to create invitation."]);
        }
    }

    private function sendInviteEmail($toEmail, $token, $role)
    {
        $mail = new PHPMailer(true);
        try {
            //Server settings
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // or SMTPS for 465
            $mail->Port = SMTP_PORT;

            //Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($toEmail);

            //Content
            $link = "https://taskmanage.iceiy.com/accept-invite/" . $token; // Update with actual frontend URL
            // Or better, use a config constant for frontend URL. 
            // Since API_BASE_URL is in frontend, backend might not know frontend URL.
            // I'll assume standard deployment relative to domain.

            // Hardcoded for now based on user context or I can use HTTP_ORIGIN?
            // User requested: "taskmanage.iceiy.com" seems to be the domain.

            $mail->isHTML(true);
            $mail->Subject = 'Invitation to join TaskManage';
            $mail->Body = "
                <h2>You have been invited!</h2>
                <p>You have been invited to join TaskManage as a <strong>" . ucfirst($role) . "</strong>.</p>
                <p>Click the link below to accept the invitation and set your password:</p>
                <p><a href='$link'>Accept Invitation</a></p>
                <p>This link will expire in 48 hours.</p>
            ";

            $mail->send();
            return ["success" => true];
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return ["success" => false, "error" => $mail->ErrorInfo];
        }
    }

    public function verifyToken($token)
    {
        $query = "SELECT * FROM user_invitations WHERE token = :token LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invite) {
            return ["valid" => false, "message" => "Invalid token."];
        }

        if ($invite['status'] !== 'pending') {
            return ["valid" => false, "message" => "Invitation already used or cancelled."];
        }

        if (strtotime($invite['expires_at']) < time()) {
            return ["valid" => false, "message" => "Invitation expired."];
        }

        return ["valid" => true, "invite" => $invite];
    }

    public function verifyInvite()
    {
        $token = $_GET['token'] ?? '';
        $result = $this->verifyToken($token);

        if ($result['valid']) {
            http_response_code(200);
            echo json_encode(["status" => "valid", "email" => $result['invite']['email']]);
        } else {
            http_response_code(400);
            echo json_encode(["status" => "invalid", "message" => $result['message']]);
        }
    }

    public function completeRegistration()
    {
        $data = json_decode(file_get_contents("php://input"));
        $token = $data->token ?? '';
        $password = $data->password ?? '';

        if (empty($token) || empty($password)) {
            http_response_code(400);
            echo json_encode(["message" => "Token and password are required."]);
            return;
        }

        // Strong Password Validation
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password)) {
            http_response_code(400);
            echo json_encode(["message" => "Password must be at least 8 characters long and include uppercase, lowercase, number, and special character."]);
            return;
        }

        $result = $this->verifyToken($token);
        if (!$result['valid']) {
            http_response_code(400);
            echo json_encode(["message" => $result['message']]);
            return;
        }

        $invite = $result['invite'];

        // Create User
        $this->userModel->username = explode('@', $invite['email'])[0]; // Default username
        $this->userModel->email = $invite['email'];
        $this->userModel->password = password_hash($password, PASSWORD_DEFAULT);
        $this->userModel->role = $invite['role'];
        $this->userModel->company_id = $invite['company_id'];
        $this->userModel->points = 0;

        if ($this->userModel->create()) {
            // Update invitation status
            $update = $this->conn->prepare("UPDATE user_invitations SET status = 'accepted' WHERE id = :id");
            $update->bindParam(':id', $invite['id']);
            $update->execute();

            http_response_code(201);
            echo json_encode(["message" => "Registration successful. You can now login."]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Failed to create user."]);
        }
    }
}
?>