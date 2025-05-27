<?php
// Router logic
class Router {
    private $routes = [];
    
    public function __construct() {
        $this->setupRoutes();
    }
    
    private function setupRoutes() {
        // Product routes
        $this->routes['GET']['/products'] = ['ProductController', 'index'];
        $this->routes['GET']['/products/(\d+)'] = ['ProductController', 'show'];
        $this->routes['POST']['/products'] = ['ProductController', 'create'];
        $this->routes['PUT']['/products/(\d+)'] = ['ProductController', 'update'];
        
        // Booking routes  
        $this->routes['GET']['/bookings'] = ['BookingController', 'index'];
        $this->routes['GET']['/bookings/(\d+)'] = ['BookingController', 'show'];
        $this->routes['POST']['/bookings'] = ['BookingController', 'create'];
        $this->routes['PUT']['/bookings/(\d+)/confirm'] = ['BookingController', 'confirm'];
        $this->routes['PUT']['/bookings/(\d+)/cancel'] = ['BookingController', 'cancel'];
        
        // Availability routes
        $this->routes['GET']['/availability'] = ['AvailabilityController', 'check'];
        $this->routes['POST']['/availability/update'] = ['AvailabilityController', 'update'];
        
        // Webhook routes
        $this->routes['POST']['/webhook/booking-status'] = ['WebhookController', 'bookingStatus'];
        $this->routes['POST']['/webhook/payment-status'] = ['WebhookController', 'paymentStatus'];
    }
    
    public function route($method, $uri) {
        $uri = parse_url($uri, PHP_URL_PATH);
        
        if (!isset($this->routes[$method])) {
            return $this->error404();
        }
        
        foreach ($this->routes[$method] as $pattern => $handler) {
            if (preg_match('#^' . $pattern . '$#', $uri, $matches)) {
                array_shift($matches);
                return $this->callHandler($handler, $matches);
            }
        }
        
        return $this->error404();
    }
    
    private function callHandler($handler, $params = []) {
        $controllerName = $handler[0];
        $methodName = $handler[1];
        
        require_once "controllers/{$controllerName}.php";
        $controller = new $controllerName();
        
        return call_user_func_array([$controller, $methodName], $params);
    }
    
    private function error404() {
        http_response_code(404);
        return json_encode(['error' => 'Route not found']);
    }
}