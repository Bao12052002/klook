<?php
// Availability controller
class AvailabilityController {
    private $availabilityModel;
    
    public function __construct() {
        $this->availabilityModel = new AvailabilityModel();
    }
    
    public function check() {
        try {
            $productId = $_GET['product_id'] ?? null;
            $date = $_GET['date'] ?? null;
            
            if (!$productId || !$date) {
                return $this->errorResponse('Product ID and date are required', 400);
            }
            
            $params = array_diff_key($_GET, ['product_id' => '', 'date' => '']);
            $availability = $this->availabilityModel->checkAvailability($productId, $date, $params);
            
            return $this->jsonResponse($availability);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    public function update() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['product_id']) || !isset($data['dates'])) {
                return $this->errorResponse('Product ID and dates are required', 400);
            }
            
            $result = $this->availabilityModel->updateAvailability($data['product_id'], $data);
            
            return $this->jsonResponse($result);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    private function jsonResponse($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        return json_encode($data);
    }
    
    private function errorResponse($message, $status = 500) {
        http_response_code($status);
        header('Content-Type: application/json');
        return json_encode(['error' => $message]);
    }
}
