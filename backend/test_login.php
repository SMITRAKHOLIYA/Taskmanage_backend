<?php
// Simulate API environment
$_SERVER['REQUEST_METHOD'] = 'POST';

// Mock Input
$mockInput = json_encode([
    "email" => "smitvirtueinfo@gmail.com",
    "password" => "whatever"
]);

// Helper to mock file_get_contents('php://input')
// Since we can't easily mock php://input in CLI without specific extensions, 
// we will rely on AuthController reading it or modify AuthController temporarily?
// Better: We instantiate User and test logic directly first.

require_once __DIR__ . "/controllers/AuthController.php";

echo "Testing User Model directly...\n";
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/models/User.php";

$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$user->email = "smitvirtueinfo@gmail.com";

echo "Checking email existence...\n";
if ($user->emailExists()) {
    echo "User found: " . $user->username . "\n";
    echo "Hash: " . $user->password . "\n";
} else {
    echo "User not found.\n";
}

echo "Test complete.\n";
?>