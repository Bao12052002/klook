<?php
// Webhook controller

class WebhookController {
    private $bookingModel;
    private $logger;
    
    public function __construct() {
        $this->bookingModel = new BookingModel();
        $this->logger = new Logger();
    }
    
    public function bookingStatus() {
        try {
            $payload = file_get_contents('php://input');
            $data = json_decode($payload, true);
            
            // Verify webhook signature
            if (!$this->verifyWebhookSignature($payload)) {
                return $this->errorResponse('Invalid signature', 401);
            }
            
            $this->logger->info('Booking status webhook received', $data);
            
            // Find booking by Klook booking ID
            $stmt = Database::getInstance()->getConnection()->prepare(
                "SELECT id FROM bookings WHERE klook_booking_id = :klook_id"
            );
            $stmt->execute(['klook_id' => $data['booking_id']]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($booking) {
                $this->bookingModel->updateBookingStatus($booking['id'], $data['status']);
                
                // Handle specific status changes
                switch ($data['status']) {
                    case 'confirmed':
                        $this->handleBookingConfirmed($booking['id'], $data);
                        break;
                    case 'cancelled':
                        $this->handleBookingCancelled($booking['id'], $data);
                        break;
                }
            }
            
            return $this->jsonResponse(['status' => 'processed']);
            
        } catch (Exception $e) {
            $this->logger->error('Webhook processing error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }
    
    public function paymentStatus() {
        try {
            $payload = file_get_contents('php://input');
            $data = json_decode($payload, true);
            
            if (!$this->verifyWebhookSignature($payload)) {
                return $this->errorResponse('Invalid signature', 401);
            }
            
            $this->logger->info('Payment status webhook received', $data);
            
            // Update payment status in bookings table
            $stmt = Database::getInstance()->getConnection()->prepare(
                "UPDATE bookings SET payment_status = :status, payment_updated_at = NOW() 
                 WHERE klook_booking_id = :klook_id"
            );
            $stmt->execute([
                'status' => $data['payment_status'],
                'klook_id' => $data['booking_id']
            ]);
            
            return $this->jsonResponse(['status' => 'processed']);
            
        } catch (Exception $e) {
            $this->logger->error('Payment webhook processing error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }
    
    private function verifyWebhookSignature($payload) {
        $signature = $_SERVER['HTTP_X_KLOOK_SIGNATURE'] ?? '';
        $expected = hash_hmac('sha256', $payload, KlookConfig::getConfig()['secret_key']);
        
        return hash_equals($expected, $signature);
    }
    
    private function handleBookingConfirmed($bookingId, $data) {
        // Send confirmation email, update inventory, etc.
        $this->logger->info("Booking {$bookingId} confirmed", $data);
    }
    
    private function handleBookingCancelled($bookingId, $data) {
        // Process refund, restore inventory, etc.
        $this->logger->info("Booking {$bookingId} cancelled", $data);
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