<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config/database.php';
require_once 'models/Database.php';

class ReviewAPI {
    private $db;
    private $requestMethod;
    private $resource;
    private $resourceId;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->requestMethod = $_SERVER['REQUEST_METHOD'];
        $this->parseRequest();
    }

    private function parseRequest() {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $segments = explode('/', trim($path, '/'));

        if (count($segments) >= 2 && $segments[0] === 'api') {
            $this->resource = $segments[1];
            $this->resourceId = isset($segments[2]) ? intval($segments[2]) : null;
        } else {
            $this->sendResponse(404, ['error' => 'Endpoint not found']);
        }
    }

    private function authenticate() {
        if (!isset($_SERVER['HTTP_X_API_KEY'])) {
            $this->sendResponse(401, ['error' => 'API key is required']);
        }

        $apiKey = $_SERVER['HTTP_X_API_KEY'];

        try {
            $stmt = $this->db->prepare("SELECT * FROM api_keys WHERE api_key = ? AND is_active = TRUE");
            $stmt->execute([$apiKey]);
            $apiKeyRecord = $stmt->fetch();

            if (!$apiKeyRecord) {
                $this->sendResponse(401, ['error' => 'Invalid API key']);
            }

            return $apiKeyRecord['user_id'];
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Authentication failed']);
        }
    }

    public function handleRequest() {
        if ($this->resource !== 'reviews') {
            $this->sendResponse(404, ['error' => 'Resource not found']);
        }

        // Аутентификация для всех endpoints кроме OPTIONS
        $userId = $this->authenticate();

        switch ($this->requestMethod) {
            case 'GET':
                $this->handleGet();
                break;
            case 'POST':
                $this->handlePost();
                break;
            case 'PUT':
                $this->handlePut();
                break;
            case 'DELETE':
                $this->handleDelete();
                break;
            default:
                $this->sendResponse(405, ['error' => 'Method not allowed']);
        }
    }

    private function handleGet() {
        if ($this->resourceId) {
            $this->getReview($this->resourceId);
        } else {
            $this->getAllReviews();
        }
    }

    private function getAllReviews() {
        try {
            $stmt = $this->db->prepare("SELECT * FROM reviews ORDER BY created_at DESC");
            $stmt->execute();
            $reviews = $stmt->fetchAll();

            $this->sendResponse(200, $reviews);
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Failed to fetch reviews']);
        }
    }

    private function getReview($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM reviews WHERE id = ?");
            $stmt->execute([$id]);
            $review = $stmt->fetch();

            if (!$review) {
                $this->sendResponse(404, ['error' => 'Review not found']);
            }

            $this->sendResponse(200, $review);
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Failed to fetch review']);
        }
    }

    private function handlePost() {
        $data = $this->getInputData();

        // Валидация
        $errors = $this->validateReviewData($data);
        if (!empty($errors)) {
            $this->sendResponse(400, ['errors' => $errors]);
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO reviews (product_id, user_name, rating, comment) 
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['product_id'],
                $data['user_name'],
                $data['rating'],
                $data['comment'] || null
            ]);

            $reviewId = $this->db->lastInsertId();

            $this->sendResponse(201, [
                'message' => 'Review created successfully',
                'id' => $reviewId
            ]);
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Failed to create review']);
        }
    }

    private function handlePut() {
        if (!$this->resourceId) {
            $this->sendResponse(400, ['error' => 'Review ID is required']);
        }

        $data = $this->getInputData();

        // Проверяем существование отзыва
        $stmt = $this->db->prepare("SELECT id FROM reviews WHERE id = ?");
        $stmt->execute([$this->resourceId]);
        if (!$stmt->fetch()) {
            $this->sendResponse(404, ['error' => 'Review not found']);
        }

        // Валидация
        $errors = $this->validateReviewData($data, false);
        if (!empty($errors)) {
            $this->sendResponse(400, ['errors' => $errors]);
        }

        try {
            $updateFields = [];
            $params = [];

            if (isset($data['product_id'])) {
                $updateFields[] = 'product_id = ?';
                $params[] = $data['product_id'];
            }
            if (isset($data['user_name'])) {
                $updateFields[] = 'user_name = ?';
                $params[] = $data['user_name'];
            }
            if (isset($data['rating'])) {
                $updateFields[] = 'rating = ?';
                $params[] = $data['rating'];
            }
            if (isset($data['comment'])) {
                $updateFields[] = 'comment = ?';
                $params[] = $data['comment'];
            }

            if (empty($updateFields)) {
                $this->sendResponse(400, ['error' => 'No fields to update']);
            }

            $params[] = $this->resourceId;
            $sql = "UPDATE reviews SET " . implode(', ', $updateFields) . " WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $this->sendResponse(200, ['message' => 'Review updated successfully']);
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Failed to update review']);
        }
    }

    private function handleDelete() {
        if (!$this->resourceId) {
            $this->sendResponse(400, ['error' => 'Review ID is required']);
        }

        try {
            // Проверяем существование отзыва
            $stmt = $this->db->prepare("SELECT id FROM reviews WHERE id = ?");
            $stmt->execute([$this->resourceId]);
            if (!$stmt->fetch()) {
                $this->sendResponse(404, ['error' => 'Review not found']);
            }

            $stmt = $this->db->prepare("DELETE FROM reviews WHERE id = ?");
            $stmt->execute([$this->resourceId]);

            $this->sendResponse(200, ['message' => 'Review deleted successfully']);
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Failed to delete review']);
        }
    }

    private function getInputData() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendResponse(400, ['error' => 'Invalid JSON data']);
        }

        return $data;
    }

    private function validateReviewData($data, $isCreate = true) {
        $errors = [];

        if ($isCreate) {
            $requiredFields = ['product_id', 'user_name', 'rating'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    $errors[] = "Field '$field' is required";
                }
            }
        }

        if (isset($data['product_id']) && (!is_numeric($data['product_id']) || $data['product_id'] <= 0)) {
            $errors[] = 'Product ID must be a positive integer';
        }

        if (isset($data['user_name'])) {
            $userName = trim($data['user_name']);
            if (empty($userName) || strlen($userName) > 100) {
                $errors[] = 'User name must not be empty and must be less than 100 characters';
            }
        }

        if (isset($data['rating']) && (!is_numeric($data['rating']) || $data['rating'] < 1 || $data['rating'] > 5)) {
            $errors[] = 'Rating must be an integer between 1 and 5';
        }

        if (isset($data['comment']) && strlen($data['comment']) > 65535) {
            $errors[] = 'Comment is too long';
        }

        return $errors;
    }

    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Запуск API
try {
    $api = new ReviewAPI();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>