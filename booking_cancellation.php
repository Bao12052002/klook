<?php
// booking_cancellation.php
ini_set('log_errors', 1);
                ini_set('error_log', __DIR__ . '/viator_api_error.log');
    $body = $GLOBALS['viator_request_data'] ?? [];
    $conn = getDB(); // nếu chưa có dòng này, cần có để dùng $conn
    $bookingRef = $body['BookingReference'] ?? null;
    $supplierConfirm = $body['SupplierConfirmationNumber'] ?? '';
    $reason = $body['Reason'] ?? '';
    $status = strtoupper($body['Status'] ?? 'CONFIRMED');
    $rejectionCode = $body['RejectionReason'] ?? '';
    $rejectionDetails = $body['RejectionReasonDetails'] ?? '';

if (!$bookingRef) {
    jsonError('BookingCancellationResponse', $body, 'Missing BookingReference');
}

// Kiểm tra booking tồn tại
$stmt = $conn->prepare("SELECT id, transaction_status, travel_date, supplier_product_code FROM tbl_viator_bookings WHERE booking_reference = ?");
$stmt->bind_param("s", $bookingRef);
$stmt->execute();
$stmt->bind_result($bookingId, $currentStatus, $travelDate, $productCode);
if (!$stmt->fetch()) {
    jsonError('BookingCancellationResponse', $body, 'Booking not found');
}
$stmt->close();

if ($currentStatus === 'CANCELLED') {
    jsonError('BookingCancellationResponse', $body, 'Booking already cancelled');
}

$cancellationNumber = 'CANCEL_' . strtoupper(bin2hex(random_bytes(4)));
$timestamp = $body['Timestamp'] ?? date('Y-m-d H:i:s');

// Lấy cutoff từ availability
$cutoffTime = null;
$stmt = $conn->prepare("SELECT booking_cutoff FROM tbl_viator_availability WHERE supplier_product_code = ? AND start_date = ?");
$stmt->bind_param("ss", $productCode, $travelDate);
$stmt->execute();
$stmt->bind_result($cutoffTime);
$stmt->fetch();
$stmt->close();

$currentTime = new DateTime();
$tourDate = new DateTime($travelDate);
$cutoff = $cutoffTime ? new DateTime($cutoffTime) : null;

if ($status !== 'REJECTED') {
    // Tự động kiểm tra cutoff và tour date
    if ($cutoff && $currentTime > $cutoff) {
        $status = 'REJECTED';
        $rejectionCode = 'PAST_CANCEL_DATE';
        $rejectionDetails = 'Cancellation not allowed after cutoff time: ' . $cutoff->format('Y-m-d H:i:s');
    } elseif ($currentTime > $tourDate) {
        $status = 'REJECTED';
        $rejectionCode = 'PAST_TOUR_DATE';
        $rejectionDetails = 'Tour date has already passed: ' . $tourDate->format('Y-m-d');
    }
}

if ($status === 'REJECTED') {
    if (!$rejectionCode || !$rejectionDetails) {
        jsonError('BookingCancellationResponse', $body, 'Missing RejectionReason or RejectionReasonDetails');
    }
    $stmt = $conn->prepare("UPDATE tbl_viator_bookings SET transaction_status = 'REJECTED', supplier_cancellation_number = ?, reason_cancel = ?, rejection_reason = ?, rejection_reason_details = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $cancellationNumber, $reason, $rejectionCode, $rejectionDetails, $bookingId);
    $stmt->execute();
    $stmt->close();

    $response = [
        'responseType' => 'BookingCancellationResponse',
        'data' => [
            'ApiKey' => API_KEY,
            'ResellerId' => RESELLER_ID,
            'SupplierId' => SUPPLIER_ID,
            'ExternalReference' => $body['ExternalReference'] ?? '',
            'Timestamp' => $timestamp,
            'RequestStatus' => [ 'Status' => 'FAILED' ],
            'BookingReference' => $bookingRef,
            'SupplierConfirmationNumber' => $supplierConfirm,
            'SupplierCancellationNumber' => $cancellationNumber,
            'TransactionStatus' => [
                'Status' => 'REJECTED',
                'RejectionReason' => $rejectionCode,
                'RejectionReasonDetails' => $rejectionDetails
            ]
        ]
    ];
} else {
    // Huỷ thành công
    $stmt = $conn->prepare("UPDATE tbl_viator_bookings SET transaction_status = 'CANCELLED', supplier_cancellation_number = ?, reason_cancel = ? WHERE id = ?");
    $stmt->bind_param("ssi", $cancellationNumber, $reason, $bookingId);
    $stmt->execute();
    $stmt->close();

    $response = [
        'responseType' => 'BookingCancellationResponse',
        'data' => [
            'ApiKey' => API_KEY,
            'ResellerId' => RESELLER_ID,
            'SupplierId' => SUPPLIER_ID,
            'ExternalReference' => $body['ExternalReference'] ?? '',
            'Timestamp' => $timestamp,
            'RequestStatus' => [ 'Status' => 'SUCCESS' ],
            'BookingReference' => $bookingRef,
            'SupplierConfirmationNumber' => $supplierConfirm,
            'SupplierCancellationNumber' => $cancellationNumber,
            'TransactionStatus' => [ 'Status' => 'CONFIRMED' ]
        ]
    ];
}

echo json_encode($response);