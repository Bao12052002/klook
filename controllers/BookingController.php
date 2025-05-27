<?php
// Booking controller

class BookingController {
    private $bookingModel;
    
    public function __construct() {
        $this->bookingModel = new BookingModel();
    }
    
    public function index() {
        try {
            $filters = $_GET;
            $bookings = $this->bookingModel->getAllBookings($filters);
            
            return $this->jsonResponse($bookings);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    public function show($id) {
        try {
            $booking = $this->bookingModel->getBooking($id);
            
            if (!$booking) {
                return $this->errorResponse('Booking not found', 404);
            }
            
            return $this->jsonResponse($booking);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    public function create() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$this->validateBookingData($data)) {
                return $this->errorResponse('Invalid booking data', 400);
            }
            
            $result = $this->bookingModel->createBooking($data);
            
            return $this->jsonResponse($result, 201);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    public function confirm($id) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $this->bookingModel->confirmBooking($id, $data);
            
            return $this->jsonResponse($result);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    public function cancel($id) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $this->bookingModel->cancelBooking($id, $data);
            
            return $this->jsonResponse($result);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
    
    private function validateBookingData($data) {
        return isset($data['product_id']) && isset($data['customer_name']) && 
               isset($data['customer_email']) && isset($data['booking_date']) &&
               isset($data['quantity']) && isset($data['total_amount']);
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