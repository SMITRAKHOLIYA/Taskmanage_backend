<?php

/* =======================
   CORS (PRODUCTION)
 ======================= */
header("Content-Type: application/json");

// Allow generic CORS if origin not present
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


/* =======================
   LOAD CORE FILES
 ======================= */
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/utils/JWTHandler.php";

/* =======================
   ROUTE NORMALIZATIONk
   FIX FOR AEONFREE
 ======================= */


/*
Valid URLs:
- /task_backend/backend/api.php
- /task_backend/backend/api.php/auth/login
- /task_backend/backend/api.php/tasks/1
*/


// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Dynamically determine base path
$scriptName = $_SERVER['SCRIPT_NAME']; // e.g., /task_backend/backend/api.php
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); // e.g., /task_backend/backend/api.php/notifications/check-reminders

// Remove script name from URI to get the path info
if (strpos($requestUri, $scriptName) === 0) {
    $cleanPath = substr($requestUri, strlen($scriptName));
} else {
    // Fallback if URL rewriting hides api.php (e.g. if accessed via /api/...)
    $dirName = dirname($scriptName);
    if ($dirName !== '/' && strpos($requestUri, $dirName) === 0) {
        $cleanPath = substr($requestUri, strlen($dirName));
    } else {
        $cleanPath = $requestUri;
    }
}



// Split path into segments
$cleanPath = trim($cleanPath, '/');
$segments = $cleanPath === '' ? [] : explode('/', $cleanPath);

// Assign routing variables
$resource = $segments[0] ?? null;
$id = null;
$action = null;
$subAction = null;

if (isset($segments[1])) {
    if (is_numeric($segments[1])) {
        $id = $segments[1];
        $action = $segments[2] ?? null;
        $subAction = $segments[3] ?? null;
    } else {
        $action = $segments[1];
        $subAction = $segments[2] ?? null;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    /* =======================
       DATABASE CONNECTION
    ======================= */
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Database connection failed."
        ]);
        exit;
    }

    /* =======================
       API ROOT CHECK
    ======================= */
    if ($resource === null) {
        echo json_encode([
            "success" => true,
            "message" => "API working"
        ]);
        exit;
    }

    /* =======================
       DISPATCHER
    ======================= */
    switch ($resource) {
        /* ---------- AUTH ---------- */
        case 'auth':
            require_once __DIR__ . "/controllers/AuthController.php";
            $controller = new AuthController();
            if ($action === "login" && $method === "POST") {
                $controller->login();
            } elseif ($action === "register" && $method === "POST") {
                $controller->register();
            } else {
                http_response_code(404);
                echo json_encode(["message" => "Auth endpoint not found"]);
            }
            break;

        /* ---------- TASKS ---------- */
        case 'tasks':
            // Check for Comments/Attachments sub-resources FIRST
            if ($id && $action === 'comments') {
                require_once __DIR__ . "/controllers/CommentController.php";
                $commentController = new CommentController();
                if ($method === 'GET') {
                    $commentController->getComments($id);
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents("php://input"));
                    if (!$data)
                        $data = new stdClass();
                    $data->task_id = $id; // Inject task ID from URL
                    $commentController->addComment($data);
                }
                break;
            }
            if ($id && $action === 'attachments') {
                require_once __DIR__ . "/controllers/CommentController.php";
                $commentController = new CommentController();
                if ($method === 'GET') {
                    $commentController->getAttachments($id);
                } elseif ($method === 'POST') {
                    // Handle file upload
                    if (isset($_FILES['file'])) {
                        $commentController->uploadAttachment($id, $_FILES['file']);
                    } else {
                        http_response_code(400);
                        echo json_encode(["message" => "No file uploaded"]);
                    }
                }
                break;
            }

            require_once __DIR__ . "/controllers/TaskController.php";
            $controller = new TaskController();
            if ($method === "GET") {
                if ($action === "trash") {
                    $controller->getTrash();
                } elseif ($action === "stats") {
                    $controller->getStats();
                } elseif ($id) {
                    $controller->getOne($id);
                } else {
                    $controller->getAll();
                }
            } elseif ($method === "POST") {
                if ($id && $action === "extend") {
                    $controller->extendDeadline($id);
                } elseif ($id && $action === "update-stage") {
                    $controller->updateStage($id);
                } else {
                    $controller->create();
                }
            } elseif ($method === "PUT" && $id) {
                $controller->update($id);
            } elseif ($method === "DELETE" && $id) {
                $controller->delete($id);
            }
            break;

        /* ---------- USERS ---------- */
        case 'users':
            require_once __DIR__ . "/controllers/UserController.php";
            $controller = new UserController();
            if ($method === "GET") {
                if ($action === "activity") {
                    if ($subAction === "all") {
                        $controller->getAllActivity();
                    } elseif ($id) {
                        $controller->getUserActivity($id);
                    } elseif (is_numeric($subAction)) {
                        $controller->getUserActivity($subAction);
                    } else {
                        http_response_code(400);
                        echo json_encode(["message" => "Activity target not specified"]);
                    }
                } else {
                    $id ? $controller->getOne($id) : $controller->getAll();
                }
            } elseif ($method === "POST") {
                if ($action === "upload-profile-pic") {
                    $controller->uploadProfilePic();
                } else {
                    $controller->create();
                }
            } elseif ($method === "DELETE" && $id) {
                $controller->delete($id);
            }
            break;

        /* ---------- RECURRING TASKS ---------- */
        case 'recurring-tasks':
            require_once __DIR__ . "/controllers/RecurringTaskController.php";
            $controller = new RecurringTaskController();
            if ($method === "POST") {
                if ($action === "generate") {
                    $controller->generateTasks();
                } else {
                    $controller->create();
                }
            } elseif ($method === "GET") {
                $id ? $controller->getOne($id) : $controller->getAll();
            }
            break;

        /* ---------- PROJECTS ---------- */
        case 'projects':
            require_once __DIR__ . "/controllers/ProjectController.php";
            $controller = new ProjectController();
            if ($method === "GET") {
                $id ? $controller->getOne($id) : $controller->getAll();
            } elseif ($method === "POST") {
                $controller->create();
            } elseif ($method === "PUT" && $id) {
                $controller->update($id);
            } elseif ($method === "DELETE" && $id) {
                $controller->delete($id);
            }
            break;

        /* ---------- ANALYTICS ---------- */
        case 'analytics':
            if ($action === "performance" && $method === "GET") {
                require_once __DIR__ . "/controllers/PerformanceController.php";
                $controller = new PerformanceController();
                $controller->getPerformanceMetrics();
            } else {
                require_once __DIR__ . "/controllers/AnalyticsController.php";
                $controller = new AnalyticsController();
                if ($method === "GET") {
                    if ($action === "overall-stats") {
                        $controller->getOverallStats();
                    } else {
                        $controller->getAdminReport();
                    }
                }
            }
            break;

        /* ---------- SETTINGS ---------- */
        case 'settings':
            require_once __DIR__ . "/controllers/SettingsController.php";
            $controller = new SettingsController();
            if ($method === "GET") {
                $controller->getSettings();
            } elseif ($method === "POST" || $method === "PUT") {
                $controller->updateSettings();
            }
            break;

        /* ---------- COMPANIES ---------- */
        case 'companies':
            require_once __DIR__ . "/controllers/CompanyController.php";
            $controller = new CompanyController();
            if ($method === "GET") {
                $id ? $controller->getOne($id) : $controller->getAll();
            } elseif ($method === "POST") {
                $controller->create();
            }
            break;

        /* ---------- NOTIFICATIONS ---------- */
        case 'notifications':
            require_once __DIR__ . "/controllers/NotificationController.php";
            $controller = new NotificationController();
            if ($method === "GET") {
                if ($action === "check-reminders") {
                    $controller->checkReminders();
                } else {
                    $controller->getUserNotifications();
                }
            } elseif ($method === "POST" && $id && $action === "read") {
                $controller->markAsRead($id);
            } elseif ($method === "DELETE") {
                if ($action === "all") {
                    $controller->deleteAll();
                } elseif ($id) {
                    $controller->delete($id);
                }
            }
            break;

        /* ---------- TASK ANSWERS ---------- */
        case 'task-answers':
            require_once __DIR__ . "/controllers/TaskAnswerController.php";
            $controller = new TaskAnswerController();
            if ($method === "GET") {
                if ($action === "task" && is_numeric($subAction)) {
                    $controller->getByTask($subAction);
                }
            } elseif ($method === "POST") {
                $controller->save();
            }
            break;

        /* ---------- SYNC ---------- */
        case 'sync':
            require_once __DIR__ . "/controllers/SyncController.php";
            $controller = new SyncController();
            if ($method === "GET") {
                $controller->getSyncStatus();
            } else {
                http_response_code(405);
                echo json_encode(["message" => "Method not allowed"]);
            }
            break;


        default:
            http_response_code(404);
            echo json_encode(["message" => "Resource not found"]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    // Log the error
    error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log("Stack Trace: " . $e->getTraceAsString());

    echo json_encode([
        "success" => false,
        "message" => "Server Error: " . $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
// Handle fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== NULL && $error['type'] === E_ERROR) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Fatal Error: " . $error['message'],
            "file" => $error['file'],
            "line" => $error['line']
        ]);
    }
});
