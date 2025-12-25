<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class ReportController
{
    private $db;
    private $jwt;
    private $company_id;
    private $user_role;

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

        $this->user_role = $decoded->role;
        $this->company_id = isset($decoded->company_id) ? $decoded->company_id : null;

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            exit();
        }

        return $decoded;
    }

    public function getUserReports()
    {
        $this->authenticate();

        $period = isset($_GET['period']) ? $_GET['period'] : 'weekly'; // weekly or monthly
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        // Base query for counting total records
        $countQuery = "SELECT COUNT(DISTINCT u.id) as total FROM users u LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status = 'completed' WHERE u.company_id = :company_id ";

        if ($period === 'weekly') {
            $countQuery .= "AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK) ";
        } elseif ($period === 'monthly') {
            $countQuery .= "AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) ";
        }

        $stmt = $this->db->prepare($countQuery);
        $stmt->bindParam(':company_id', $this->company_id);
        $stmt->execute();
        $totalItems = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $totalPages = ceil($totalItems / $limit);

        // Main query for fetching data
        $query = "SELECT u.id, u.username, u.email, u.role, u.points, u.created_at, COUNT(t.id) as tasks_completed 
                  FROM users u 
                  LEFT JOIN tasks t ON u.id = t.assigned_to 
                  AND t.status = 'completed' 
                  WHERE u.company_id = :company_id ";

        if ($period === 'weekly') {
            $query .= "AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK) ";
        } elseif ($period === 'monthly') {
            $query .= "AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) ";
        }

        $query .= "GROUP BY u.id ORDER BY tasks_completed DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':company_id', $this->company_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'data' => $reports,
            'meta' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'limit' => $limit
            ]
        ];

        http_response_code(200);
        echo json_encode($response);
    }
}
?>