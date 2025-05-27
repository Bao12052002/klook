
<?php

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/viator_api_error.log');
$body = $GLOBALS['viator_request_data'] ?? [];
$conn = getDB();

$bookingRef = $body['BookingReference'] ?? null;
$timestamp = $body['Timestamp'] ?? gmdate("Y-m-d\TH:i:s.v\Z");
$externalRef = $body['ExternalReference'] ?? '';
$SupplierConfirmationNumber = $body['SupplierConfirmationNumber'] ?? [];
$TravelDate = $body['TravelDate'] ?? [];

if (!$bookingRef) {
    jsonError('RedemptionResponse', $body, 'Missing BookingReference');
}
if (empty($TravelDate)) {
    jsonError('RedemptionResponse', $body, 'Missing TravelDate');
}
if (empty($SupplierConfirmationNumber)) {
    jsonError('RedemptionResponse', $body, 'Missing SupplierConfirmationNumber');
}

// Kiểm tra booking tồn tại
$stmt = $conn->prepare("SELECT id, transaction_status, travellers FROM tbl_viator_bookings WHERE booking_reference = ?");
$stmt->bind_param("s", $bookingRef);
$stmt->execute();
$stmt->bind_result($bookingId, $status, $travellerJson);
if (!$stmt->fetch()) {
    jsonError('RedemptionResponse', $body, 'Booking not found');
}
$stmt->close();

if ($status !== 'CONFIRMED') {
    jsonError('RedemptionResponse', $body, 'Only CONFIRMED bookings can be redeemed');
}

// Cập nhật trạng thái là REDEEMED
$stmt = $conn->prepare("UPDATE tbl_viator_bookings SET transaction_status = 'REDEEMED', redeemed_at = ? WHERE id = ?");
$stmt->bind_param("si", $timestamp, $bookingId);
$stmt->execute();
$stmt->close();

// Lấy danh sách TravellerIdentifier từ DB
$existingTravellers = json_decode($travellerJson, true);
$confirmedTravellers = [];

foreach ($existingTravellers as $index => $traveller) {
    $identifier = $traveller['TravellerIdentifier'] ?? (string)($index + 1);
    $confirmedTravellers[] = [
        'TravellerIdentifier' => $identifier,
        'TravellerSupplierConfirmationNumber' => random_int(1000, 9999) . $identifier,
        'RedemptionStatus' => true,
        'RedemptionDateTime' => $timestamp
    ];
}

// Nếu có người không nằm trong danh sách chính, bạn có thể append thêm:
foreach ($travellers as $sent) {
    $found = false;
    foreach ($confirmedTravellers as $confirmed) {
        if ($confirmed['TravellerIdentifier'] === $sent['TravellerIdentifier']) {
            $found = true;
            break;
        }
    }
    if (!$found && !empty($sent['TravellerIdentifier'])) {
        $confirmedTravellers[] = [
            'TravellerIdentifier' => $sent['TravellerIdentifier'],
            'RedemptionStatus' => false
        ];
    }
}

$response = [
    'responseType' => 'RedemptionResponse',
    'data' => [
        'ApiKey' => API_KEY,
        'ResellerId' => RESELLER_ID,
        'SupplierId' => SUPPLIER_ID,
        'ExternalReference' => $externalRef,
        'Timestamp' => $timestamp,
        'RequestStatus' => ['Status' => 'SUCCESS'],
        'RedemptionStatus' => true,
        'Traveller' => $confirmedTravellers
    ]
];

echo json_encode($response);
?>
