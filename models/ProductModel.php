<?php
// Product model
class ProductModel {
    private $db;
    private $klookAPI;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->klookAPI = new KlookAPIService();
    }
    
    public function getAllProducts($filters = []) {
        // Get from local DB first
        $sql = "SELECT * FROM products WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params['status'] = $filters['status'];
        }
        
        if (!empty($filters['category'])) {
            $sql .= " AND category = :category";
            $params['category'] = $filters['category'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $localProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Sync with Klook API
        try {
            $klookProducts = $this->klookAPI->getProducts($filters);
            $this->syncProducts($klookProducts['data'] ?? []);
        } catch (Exception $e) {
            error_log("Failed to sync products: " . $e->getMessage());
        }
        
        return $localProducts;
    }
    
    public function getProduct($id) {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Get latest data from Klook
            try {
                $klookProduct = $this->klookAPI->getProduct($product['klook_product_id']);
                $this->updateProduct($id, $klookProduct['data']);
                return $klookProduct['data'];
            } catch (Exception $e) {
                error_log("Failed to get product from Klook: " . $e->getMessage());
                return $product;
            }
        }
        
        return null;
    }
    
    public function createProduct($data) {
        try {
            // Create on Klook first
            $klookResponse = $this->klookAPI->createProduct($data);
            
            // Save to local DB
            $sql = "INSERT INTO products (klook_product_id, name, description, category, price, status, created_at) 
                    VALUES (:klook_product_id, :name, :description, :category, :price, :status, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'klook_product_id' => $klookResponse['data']['id'],
                'name' => $data['name'],
                'description' => $data['description'],
                'category' => $data['category'],
                'price' => $data['price'],
                'status' => 'active'
            ]);
            
            return $klookResponse;
            
        } catch (Exception $e) {
            throw new Exception("Failed to create product: " . $e->getMessage());
        }
    }
    
    public function updateProduct($id, $data) {
        $stmt = $this->db->prepare("SELECT klook_product_id FROM products WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            throw new Exception("Product not found");
        }
        
        try {
            // Update on Klook
            $klookResponse = $this->klookAPI->updateProduct($product['klook_product_id'], $data);
            
            // Update local DB
            $sql = "UPDATE products SET name = :name, description = :description, 
                    category = :category, price = :price, updated_at = NOW() WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'name' => $data['name'],
                'description' => $data['description'],
                'category' => $data['category'],
                'price' => $data['price']
            ]);
            
            return $klookResponse;
            
        } catch (Exception $e) {
            throw new Exception("Failed to update product: " . $e->getMessage());
        }
    }
    
    private function syncProducts($klookProducts) {
        foreach ($klookProducts as $product) {
            $stmt = $this->db->prepare("SELECT id FROM products WHERE klook_product_id = :klook_id");
            $stmt->execute(['klook_id' => $product['id']]);
            
            if ($stmt->fetch()) {
                // Update existing
                $sql = "UPDATE products SET name = :name, description = :description, 
                        price = :price, status = :status, updated_at = NOW() 
                        WHERE klook_product_id = :klook_id";
            } else {
                // Insert new
                $sql = "INSERT INTO products (klook_product_id, name, description, price, status, created_at) 
                        VALUES (:klook_id, :name, :description, :price, :status, NOW())";
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'klook_id' => $product['id'],
                'name' => $product['name'],
                'description' => $product['description'],
                'price' => $product['price'],
                'status' => $product['status']
            ]);
        }
    }
}