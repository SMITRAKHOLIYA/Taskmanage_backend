<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../vendor/autoload.php';

class EmailService
{
    private $mail;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);
        // Server settings
        $this->mail->isSMTP();
        $this->mail->Host = SMTP_HOST;
        $this->mail->SMTPAuth = true;
        $this->mail->Username = SMTP_USER;
        $this->mail->Password = SMTP_PASS;
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mail->Port = SMTP_PORT;
        $this->mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $this->mail->isHTML(true);
    }

    public function sendPasswordResetEmail($toEmail, $token)
    {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($toEmail);

            $link = "https://taskmanage.iceiy.com/reset-password/" . $token;

            $this->mail->Subject = 'Reset Your Password - TaskManage';
            $this->mail->Body = "
                <h2>Password Reset Request</h2>
                <p>We received a request to reset your password.</p>
                <p>Click the link below to set a new password:</p>
                <p><a href='$link' target='_blank'>Reset Password</a></p>
                <p>This link will expire in 1 hour.</p>
                <p>If you didn't request a password reset, you can safely ignore this email.</p>
            ";

            $this->mail->send();
            return ["success" => true];
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$this->mail->ErrorInfo}");
            return ["success" => false, "error" => $this->mail->ErrorInfo];
        }
    }
}
?>