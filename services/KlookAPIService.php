<?php
class KlookAPIService {
    private $config;
    private $httpClient;
    private $logger;
    private $rateLimiter;
    
    public function __construct() {
        $this->config = KlookConfig::getConfig();
        $this->httpClient = new HttpClient();
        $this->logger = new Logger();
        $this->rateLimiter = new RateLimiter();
    }
    
    // Product APIs
    public function getProducts($params = []) {
        return $this->makeRequest('GET', 'products', $params);
    }
    
    public function getProduct($productId) {
        return $this->makeRequest('GET', "products/{$productId}");
    }
    
    public function createProduct($productData) {
        return $this->makeRequest('POST', 'products', $productData);
    }
    
    public function updateProduct($productId, $productData) {
        return $this->makeRequest('PUT', "products/{$productId}", $productData);
    }
    
    // Booking APIs
    public function getBookings($params = []) {
        return $this->makeRequest('GET', 'bookings', $params);
    }
    
    public function getBooking($bookingId) {
        return $this->makeRequest('GET', "bookings/{$bookingId}");
    }
    
    public function createBooking($bookingData) {
        return $this->makeRequest('POST', 'bookings', $bookingData);
    }
    
    public function confirmBooking($bookingId, $confirmationData) {
        return $this->makeRequest('PUT', "bookings/{$bookingId}/confirm", $confirmationData);
    }
    
    public function cancelBooking($bookingId, $cancellationData) {
        return $this->makeRequest('PUT', "bookings/{$bookingId}/cancel", $cancellationData);
    }
    
    // Availability APIs
    public function checkAvailability($productId, $date, $params = []) {
        $params['product_id'] = $productId;
        $params['date'] = $date;
        return $this->makeRequest('GET', 'availability', $params);
    }
    
    public function updateAvailability($productId, $availabilityData) {
        return $this->makeRequest('POST', "products/{$productId}/availability", $availabilityData);
    }
    
    private function makeRequest($method, $endpoint, $data = null) {
        try {
            // Rate limiting
            $this->rateLimiter->checkLimit();
            
            $url = $this->config['base_url'] . $endpoint;
            $headers = $this->buildHeaders();
            
            $this->logger->info("API Request: {$method} {$url}", ['data' => $data]);
            
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'json' => $data,
                'timeout' => $this->config['timeout']
            ]);
            
            $this->logger->info("API Response", ['response' => $response]);
            
            return $this->parseResponse($response);
            
        } catch (Exception $e) {
            $this->logger->error("API Error: " . $e->getMessage(), [
                'method' => $method,
                'endpoint' => $endpoint,
                'data' => $data
            ]);
            throw $e;
        }
    }
    
    private function buildHeaders() {
        $timestamp = time();
        $signature = $this->generateSignature($timestamp);
        
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->config['api_key'],
            'X-Klook-Timestamp' => $timestamp,
            'X-Klook-Signature' => $signature,
            'X-Klook-Supplier-Id' => $this->config['supplier_id']
        ];
    }
    
    private function generateSignature($timestamp) {
        $message = $this->config['api_key'] . $timestamp;
        return hash_hmac('sha256', $message, $this->config['secret_key']);
    }
    
    private function parseResponse($response) {
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response');
        }
        
        if (isset($data['error'])) {
            throw new Exception($data['error']['message'] ?? 'API Error');
        }
        
        return $data;
    }
}