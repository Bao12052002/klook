<?php
// Product controller

class ProductController {
    private $productModel;
    
    public function __construct() {
        $this->productModel = new ProductModel();
    }
    
    public function index() {
        try {
            $filters = $_GET;
            $products = $this->productModel->getAllProducts($filters);
            
            return $this->jsonResponse($products);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    public function show($id) {
        try {
            $product = $this->productModel->getProduct($id);
            
            if (!$product) {
                return $this->errorResponse('Product not found', 404);
            }
            
            return $this->jsonResponse($product);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    public function create() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$this->validateProductData($data)) {
                return $this->errorResponse('Invalid product data', 400);
            }
            
            $result = $this->productModel->createProduct($data);
            
            return $this->jsonResponse($result, 201);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    public function update($id) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$this->validateProductData($data)) {
                return $this->errorResponse('Invalid product data', 400);
            }
            
            $result = $this->productModel->updateProduct($id, $data);
            
            return $this->jsonResponse($result);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    private function validateProductData($data) {
        return isset($data['name']) && isset($data['description']) && 
               isset($data['category']) && isset($data['price']);
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