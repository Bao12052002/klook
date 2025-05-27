<?php
// Availability model

class AvailabilityModel {
    private $db;
    private $klookAPI;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->klookAPI = new KlookAPIService();
    }
    
    public function checkAvailability($productId, $date, $params = []) {
        try {
            // Check Klook availability
            $klookAvailability = $this->klookAPI->checkAvailability($productId, $date, $params);
            
            // Update local cache
            $this->updateAvailabilityCache($productId, $date, $klookAvailability['data']);
            
            return $klookAvailability;
            
        } catch (Exception $e) {
            // Fallback to cached data
            return $this->getCachedAvailability($productId, $date);
        }
    }
    
    public function updateAvailability($productId, $availabilityData) {
        try {
            $klookResponse = $this->klookAPI->updateAvailability($productId, $availabilityData);
            
            // Update local cache
            foreach ($availabilityData['dates'] as $dateData) {
                $this->updateAvailabilityCache($productId, $dateData['date'], $dateData);
            }
            
            return $klookResponse;
            
        } catch (Exception $e) {
            throw new Exception("Failed to update availability: " . $e->getMessage());
        }
    }
    
    private function updateAvailabilityCache($productId, $date, $data) {
        $sql = "INSERT INTO availability_cache (product_id, date, available_slots, price, updated_at) 
                VALUES (:product_id, :date, :slots, :price, NOW()) 
                ON DUPLICATE KEY UPDATE 
                available_slots = :slots, price = :price, updated_at = NOW()";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'product_id' => $productId,
            'date' => $date,
            'slots' => $data['available_slots'] ?? 0,
            'price' => $data['price'] ?? 0
        ]);
    }
    
    private function getCachedAvailability($productId, $date) {
        $stmt = $this->db->prepare("
            SELECT * FROM availability_cache 
            WHERE product_id = :product_id AND date = :date 
            AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute(['product_id' => $productId, 'date' => $date]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}