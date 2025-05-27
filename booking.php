<?php
// booking.php – Tích hợp logic từ bạn và chuẩn hoá theo Viator OpenAPI
ini_set('log_errors', 1);
                ini_set('error_log', __DIR__ . '/viator_api_error.log');
                $body = $GLOBALS['viator_request_data'] ?? [];
                $conn = getDB(); // nếu chưa có dòng này, cần có để dùng $conn

if (!isset($body['SupplierProductCode'], $body['TravelDate'], $body['TravellerMix'], $body['CurrencyCode'], $body['Amount'])) {
    jsonError('BookingResponse', $body, 'Missing required booking fields');
}

$productCode = $body['SupplierProductCode'];
$startDate = $body['TravelDate'];
$travellerMix = $body['TravellerMix'];
$totalTravellers = (int)($travellerMix['Total'] ?? 0);
$currentTime = new DateTime();

// Truy vấn availability
$stmt = $conn->prepare("SELECT capacity, booking_cutoff, is_blocked FROM tbl_viator_availability WHERE supplier_product_code = ? AND start_date = ?");
$stmt->bind_param("ss", $productCode, $startDate);
$stmt->execute();
$stmt->bind_result($capacity, $booking_cutoff, $isBlocked);
if (!$stmt->fetch()) {
    jsonError('BookingResponse', $body, 'NO_EVENT');
}
$stmt->close();

if ((int)$isBlocked === 1) {
    jsonError('BookingResponse', $body, 'BLOCKED_OUT');
}
if ($capacity < $totalTravellers) {
    jsonError('BookingResponse', $body, 'SOLD_OUT');
}
if ($booking_cutoff && $currentTime > new DateTime($booking_cutoff)) {
    jsonError('BookingResponse', $body, 'PAST_CUTOFF_DATE');
}

// Kiểm tra traveller mix nếu có cấu hình
$stmt = $conn->prepare("SELECT id FROM tblitems WHERE supplier_product_code = ?");
$stmt->bind_param("s", $productCode);
$stmt->execute();
$stmt->bind_result($tourId);
$tourExists = $stmt->fetch();
$stmt->close();

if ($tourExists) {
    $stmt = $conn->prepare("SELECT adult_allowed, child_allowed, youth_allowed, infant_allowed, senior_allowed FROM tbl_viator_traveller_restrictions WHERE tour_id = ?");
    $stmt->bind_param("i", $tourId);
    $stmt->execute();
    $stmt->bind_result($adult, $child, $youth, $infant, $senior);
    if ($stmt->fetch()) {
        $allowed = [
            'Adult' => (bool)$adult,
            'Child' => (bool)$child,
            'Youth' => (bool)$youth,
            'Infant' => (bool)$infant,
            'Senior' => (bool)$senior
        ];
        foreach ($travellerMix as $type => $count) {
            if ($count > 0 && isset($allowed[$type]) && !$allowed[$type]) {
                jsonError('BookingResponse', $body, 'TRAVELLER_MISMATCH');
            }
        }
    }
    $stmt->close();
}

// Tạo booking
$supplierConfirmationNumber = 'CN' . strtoupper(substr(uniqid(), -6));
$bookingReference = $body['BookingReference'] ?? ('BOOK-' . strtoupper(uniqid()));
$tourOptionCode = $body['TourOptions']['SupplierOptionCode'] ?? '';
$inclusions = isset($body['Inclusions']['Inclusion']) ? json_encode($body['Inclusions']['Inclusion']) : '';
$requiredInfo = isset($body['RequiredInfo']['Question']) ? json_encode($body['RequiredInfo']['Question']) : '';
$specialRequirement = $body['SpecialRequirement'] ?? '';
$pickupPoint = $body['PickupPoint'] ?? '';
$contactDetail = isset($body['ContactDetail']) ? json_encode($body['ContactDetail']) : '';
$contactEmail = $body['ContactEmail'] ?? '';
$location = $body['Location'] ?? '';
$holdRef = $body['AvailabilityHoldReference'] ?? '';
$currency = $body['CurrencyCode'];
$amount = $body['Amount'];
$externalRef = $body['ExternalReference'] ?? '';
$requestId = $body['RequestId'] ?? null;
$resellerId = $body['ResellerId'] ?? RESELLER_ID;

$travellers = $body['Traveller'] ?? [];
$updatedTravellers = [];
foreach ($travellers as $index => $traveller) {
    $updatedTravellers[] = [
        'TravellerIdentifier' => $traveller['TravellerIdentifier'] ?? (string)($index + 1),
        'TravellerSupplierConfirmationNumber' => '',
        'TravellerSeat' => '',
        'TravellerBarcode' => '9990009990009999' . str_pad($index, 2, '0', STR_PAD_LEFT)
    ];
}
$travellerJson = json_encode($updatedTravellers);
$travellerMixJson = json_encode($travellerMix);
$travellerTypes = json_encode(array_keys(array_filter($travellerMix, fn($c) => $c > 0)));
$tourBarcode = '999000999000' . rand(1000, 9999);

$stmt = $conn->prepare("INSERT INTO tbl_viator_bookings (
    supplier_product_code, travel_date, booking_reference, supplier_confirmation_number,
    viator_booking_reference, reseller_id, external_reference, location, tour_option_code,
    inclusions, travellers, traveller_mix, traveller_types, required_info, special_requirement,
    pickup_point, contact_detail, contact_email, availability_hold_reference, total_price,
    currency_code, request_id, transaction_status, tour_barcode
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CONFIRMED', ?)");
$stmt->bind_param("sssssssssssssssssssssis",  // ✅ 23 kiểu
$productCode, $startDate, $bookingReference, $supplierConfirmationNumber,
$bookingReference, $resellerId, $externalRef, $location, $tourOptionCode,
$inclusions, $travellerJson, $travellerMixJson, $travellerTypes,
$requiredInfo, $specialRequirement, $pickupPoint, $contactDetail,
$contactEmail, $holdRef, $amount, $currency, $requestId, $tourBarcode);
$stmt->execute();
$stmt->close();

// Trừ capacity
$stmt = $conn->prepare("UPDATE tbl_viator_availability SET capacity = capacity - ? WHERE supplier_product_code = ? AND start_date = ?");
$stmt->bind_param("iss", $totalTravellers, $productCode, $startDate);
$stmt->execute();
$stmt->close();

$response = [
    'responseType' => 'BookingResponse',
    'data' => [
        'ApiKey' => API_KEY,
        'ResellerId' => $resellerId,
        'SupplierId' => SUPPLIER_ID,
        'ExternalReference' => $externalRef,
        'Timestamp' => $body['Timestamp'] ?? gmdate("Y-m-d\TH:i:s.v\Z"),
        'RequestStatus' => [ 'Status' => 'SUCCESS' ],
        'BookingReference' => $bookingReference,
        'SupplierCommentCustomer' => 'yêu cầu từ supplier tới customer',
        'Traveller' => $updatedTravellers,
        'TransactionStatus' => [ 'Status' => 'CONFIRMED' ],
        'SupplierConfirmationNumber' => $supplierConfirmationNumber
    ]
];

echo json_encode($response);
