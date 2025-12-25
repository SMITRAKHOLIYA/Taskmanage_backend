<?php
// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/controllers/TaskController.php';

// 1. Connect to DB
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("Database connection failed.\n");
}

echo "Database connected.\n";

// 2. Check/Reset Admin User
$user = new User($db);
$email = 'admin@example.com';
$password = 'password';

$user->email = $email;
$exists = $user->emailExists();

if ($exists) {
    echo "User $email found. Resetting password...\n";
    // Update password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $query = "UPDATE users SET password = :password WHERE email = :email";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':password', $hashed_password);
    $stmt->bindParam(':email', $email);
    if ($stmt->execute()) {
        echo "Password reset to '$password'.\n";
    } else {
        echo "Failed to reset password.\n";
    }
} else {
    echo "User $email NOT found. Creating...\n";
    $user->username = 'Admin';
    $user->password = password_hash($password, PASSWORD_DEFAULT);
    $user->role = 'admin';
    if ($user->create()) {
        echo "User created with password '$password'.\n";
    } else {
        echo "Failed to create user.\n";
    }
}

// 3. Check Task Counts directly in DB
$query = "SELECT COUNT(*) as total FROM tasks WHERE deleted_at IS NULL";
$stmt = $db->prepare($query);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Total Tasks in DB (direct query): " . $row['total'] . "\n";

// 4. Test TaskController::getStats logic (simulated)
// We need to bypass authentication or manually call the model method.
// Let's call the model method directly to verify logic.
require_once __DIR__ . '/models/Task.php';
$taskModel = new Task($db);
// Admin ID is likely 1, role 'admin'
$adminStmt = $db->prepare("SELECT id FROM users WHERE email = :email");
$adminStmt->bindParam(':email', $email);
$adminStmt->execute();
$adminId = $adminStmt->fetchColumn();

echo "Admin ID: $adminId\n";

$stats = $taskModel->getStatistics($adminId, 'admin', null);
echo "Stats from Task Model:\n";
print_r($stats);

?>