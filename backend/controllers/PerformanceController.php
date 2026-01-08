<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../utils/JWTHandler.php';

class PerformanceController
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
        $this->company_id = isset($decoded->company_id) ? $decoded->company_id : null;
        return true;
    }

    public function getPerformanceMetrics()
    {
        $this->authenticate();

        if ($this->user_role !== 'admin' && $this->user_role !== 'manager' && $this->user_role !== 'owner') {
            http_response_code(403);
            echo json_encode(array("message" => "Access denied."));
            return;
        }

        $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;

        if (!$startDate || !$endDate) {
            http_response_code(400);
            echo json_encode(["message" => "Start date and End date are required."]);
            return;
        }

        // Add time to end date to make it inclusive for the whole day
        $query = "SELECT 
                    u.id, 
                    u.username, 
                    u.email, 
                    COUNT(t.id) as completed_tasks,
                    (COUNT(t.id) * 10) as calculated_points
                  FROM users u
                  LEFT JOIN tasks t ON u.id = t.assigned_to 
                    AND t.status = 'completed'
                    AND t.completed_at >= :start_date 
                    AND t.completed_at <= :end_date
                    AND t.deleted_at IS NULL
                  WHERE 1=1";

        // Company Isolation
        if ($this->company_id) {
            $query .= " AND u.company_id = :company_id";
        }

        $query .= " GROUP BY u.id, u.username, u.email";
        $query .= " ORDER BY calculated_points DESC";

        $stmt = $this->db->prepare($query);

        // Bind params
        $endDateFull = $endDate . " 23:59:59";
        $stmt->bindParam(":start_date", $startDate);
        $stmt->bindParam(":end_date", $endDateFull);

        if ($this->company_id) {
            $stmt->bindParam(":company_id", $this->company_id);
        }

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format for Frontend
        $topUsers = [];
        $distribution = [
            'High Performers' => 0,
            'Average' => 0,
            'Needs Improvement' => 0
        ];

        foreach ($results as $row) {
            $points = (int) $row['calculated_points'];
            $row['points'] = $points; // Add clean points key
            $topUsers[] = $row;

            if ($points > 100)
                $distribution['High Performers']++;
            elseif ($points > 50)
                $distribution['Average']++;
            else
                $distribution['Needs Improvement']++;
        }

        $distributionData = [
            ['name' => 'High Performers', 'value' => $distribution['High Performers'], 'color' => '#10B981'],
            ['name' => 'Average', 'value' => $distribution['Average'], 'color' => '#3B82F6'],
            ['name' => 'Needs Imp.', 'value' => $distribution['Needs Improvement'], 'color' => '#F59E0B']
        ];

        echo json_encode([
            'topUsers' => $topUsers,
            'distribution' => $distributionData
        ]);
    }
}
