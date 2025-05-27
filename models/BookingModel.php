<?php
// Booking model

class BookingModel {
    private $db;
    private $klookAPI;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->klookAPI = new KlookAPIService();
    }
    
    public function getAllBookings($filters = []) {
        $sql = "SELECT b.*, p.name as product_name FROM bookings b 
                LEFT JOIN products p ON b.product_id = p.id WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND b.status = :status";
            $params['status'] = $filters['status'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND b.booking_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND b.booking_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY b.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getBooking($id) {
        $stmt = $this->db->prepare("
            SELECT b.*, p.name as product_name 
            FROM bookings b 
            LEFT JOIN products p ON b.product_id = p.id 
            WHERE b.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($booking && $booking['klook_booking_id']) {
            // Sync with Klook status
            try {
                $klookBooking = $this->klookAPI->getBooking($booking['klook_booking_id']);
                $this->updateBookingStatus($id, $klookBooking['data']['status']);
                $booking['status'] = $klookBooking['data']['status'];
            } catch (Exception $e) {
                error_log("Failed to sync booking status: " . $e->getMessage());
            }
        }
        
        return $booking;
    }
    
    public function createBooking($data) {
        $this->db->beginTransaction();
        
        try {
            // Insert local booking first
            $sql = "INSERT INTO bookings (product_id, customer_name, customer_email, 
                    customer_phone, booking_date, quantity, total_amount, status, created_at) 
                    VALUES (:product_id, :customer_name, :customer_email, :customer_phone, 
                    :booking_date, :quantity, :total_amount, 'pending', NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'product_id' => $data['product_id'],
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'],
                'booking_date' => $data['booking_date'],
                'quantity' => $data['quantity'],
                'total_amount' => $data['total_amount']
            ]);
            
            $bookingId = $this->db->lastInsertId();
            
            // Create booking on Klook
            $klookBookingData = $this->prepareKlookBookingData($data, $bookingId);
            $klookResponse = $this->klookAPI->createBooking($klookBookingData);
            
            // Update with Klook booking ID
            $stmt = $this->db->prepare("UPDATE bookings SET klook_booking_id = :klook_id WHERE id = :id");
            $stmt->execute([
                'klook_id' => $klookResponse['data']['id'],
                'id' => $bookingId
            ]);
            
            $this->db->commit();
            
            return [
                'id' => $bookingId,
                'klook_booking_id' => $klookResponse['data']['id'],
                'status' => 'pending'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw new Exception("Failed to create booking: " . $e->getMessage());
        }
    }
    
    public function confirmBooking($id, $confirmationData) {
        $booking = $this->getBooking($id);
        if (!$booking) {
            throw new Exception("Booking not found");
        }
        
        try {
            $klookResponse = $this->klookAPI->confirmBooking($booking['klook_booking_id'], $confirmationData);
            
            $this->updateBookingStatus($id, 'confirmed');
            
            // Update confirmation details
            $sql = "UPDATE bookings SET confirmation_code = :code, confirmed_at = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'code' => $confirmationData['confirmation_code'] ?? '',
                'id' => $id
            ]);
            
            return $klookResponse;
            
        } catch (Exception $e) {
            throw new Exception("Failed to confirm booking: " . $e->getMessage());
        }
    }
    
    public function cancelBooking($id, $cancellationData) {
        $booking = $this->getBooking($id);
        if (!$booking) {
            throw new Exception("Booking not found");
        }
        
        try {
            $klookResponse = $this->klookAPI->cancelBooking($booking['klook_booking_id'], $cancellationData);
            
            $this->updateBookingStatus($id, 'cancelled');
            
            // Update cancellation details
            $sql = "UPDATE bookings SET cancellation_reason = :reason, cancelled_at = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'reason' => $cancellationData['reason'] ?? '',
                'id' => $id
            ]);
            
            return $klookResponse;
            
        } catch (Exception $e) {
            throw new Exception("Failed to cancel booking: " . $e->getMessage());
        }
    }
    
    public function updateBookingStatus($id, $status) {
        $sql = "UPDATE bookings SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['status' => $status, 'id' => $id]);
    }
    
    private function prepareKlookBookingData($data, $localBookingId) {
        return [
            'product_id' => $data['klook_product_id'] ?? $data['product_id'],
            'booking_date' => $data['booking_date'],
            'quantity' => $data['quantity'],
            'customer' => [
                'name' => $data['customer_name'],
                'email' => $data['customer_email'],
                'phone' => $data['customer_phone']
            ],
            'reference_id' => "LOCAL_" . $localBookingId,
            'total_amount' => $data['total_amount']
        ];
    }
}