<?php
// booking_amendment.php – Kiểm tra cutoff, capacity và traveller_restrictions trước khi sửa booking
ini_set('log_errors', 1);
                ini_set('error_log', __DIR__ . '/viator_api_error.log');
                $body = $GLOBALS['viator_request_data'] ?? [];
$conn = getDB(); // nếu chưa có dòng này, cần có để dùng $conn

$required = ['BookingReference', 'TravelDate', 'SupplierProductCode'];
foreach ($required as $field) {
    if (empty($body[$field])) {
        jsonError('BookingAmendmentResponse', $body, "Missing field: $field");
    }
}

$bookingRef = $body['BookingReference'];
$newDate = $body['TravelDate'];
$newProduct = $body['SupplierProductCode'];
$tourOptionCode = $body['TourOptions']['SupplierOptionCode'] ?? '';
$travellerMix = $body['TravellerMix'] ?? [];
$totalTravellers = (int)($travellerMix['Total'] ?? 0);

// Kiểm tra availability theo ngày mới
$stmt = $conn->prepare("SELECT capacity, booking_cutoff, is_blocked FROM tbl_viator_availability WHERE supplier_product_code = ? AND start_date = ?");
$stmt->bind_param("ss", $newProduct, $newDate);
$stmt->execute();
$stmt->bind_result($capacity, $cutoff, $isBlocked);
if (!$stmt->fetch()) {
    jsonError('BookingAmendmentResponse', $body, 'NO_EVENT');
}
$stmt->close();

if ((int)$isBlocked === 1) {
    jsonError('BookingAmendmentResponse', $body, 'BLOCKED_OUT');
}
if ($capacity < $totalTravellers) {
    jsonError('BookingAmendmentResponse', $body, 'SOLD_OUT');
}
if ($cutoff && (new DateTime()) > new DateTime($cutoff)) {
    jsonError('BookingAmendmentResponse', $body, 'PAST_CUTOFF_DATE');
}

// Kiểm tra traveller restrictions
$stmt = $conn->prepare("SELECT id FROM tblitems WHERE supplier_product_code = ?");
$stmt->bind_param("s", $newProduct);
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
                jsonError('BookingAmendmentResponse', $body, 'TRAVELLER_MISMATCH');
            }
        }
    }
    $stmt->close();
}

// Cập nhật booking
$inclusions = isset($body['Inclusions']['Inclusion']) ? json_encode($body['Inclusions']['Inclusion']) : '';
$requiredInfo = isset($body['RequiredInfo']['Question']) ? json_encode($body['RequiredInfo']['Question']) : '';
$specialRequirement = $body['SpecialRequirement'] ?? '';
$pickupPoint = $body['PickupPoint'] ?? '';
$contactDetail = isset($body['ContactDetail']) ? json_encode($body['ContactDetail']) : '';
$contactEmail = $body['ContactEmail'] ?? '';
$location = $body['Location'] ?? '';
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
$travellersJson = json_encode($updatedTravellers);
$mixJson = json_encode($travellerMix);
$typesJson = json_encode(array_keys(array_filter($travellerMix, fn($v) => $v > 0)));

$stmt = $conn->prepare("UPDATE tbl_viator_bookings SET 
    travel_date = ?, supplier_product_code = ?, tour_option_code = ?, 
    location = ?, inclusions = ?, required_info = ?, special_requirement = ?, pickup_point = ?, 
    contact_detail = ?, contact_email = ?, traveller_mix = ?, traveller_types = ?, travellers = ? 
    WHERE booking_reference = ?");

$stmt->bind_param(
    "ssssssssssssss",
    $newDate, $newProduct, $tourOptionCode,
    $location, $inclusions, $requiredInfo, $specialRequirement, $pickupPoint,
    $contactDetail, $contactEmail, $mixJson, $typesJson, $travellersJson, $bookingRef
);
$stmt->execute();
$stmt->close();

$response = [
    'responseType' => 'BookingAmendmentResponse',
    'data' => [
        'ApiKey' => API_KEY,
        'ResellerId' => RESELLER_ID,
        'SupplierId' => SUPPLIER_ID,
        'ExternalReference' => $body['ExternalReference'] ?? '',
        'Timestamp' => $body['Timestamp'] ?? gmdate("Y-m-d\TH:i:s.v\Z"),
        'RequestStatus' => ['Status' => 'SUCCESS'],
        'BookingReference' => $bookingRef,
        'SupplierCommentCustomer' => $body['SupplierNote'] ?? '',
        'TourBarcode' => '',
        'Traveller' => $updatedTravellers,
        'TransactionStatus' => [ 'Status' => 'CONFIRMED' ],
        'SupplierConfirmationNumber' => $body['SupplierConfirmationNumber'] ?? ''
    ]
];

echo json_encode($response);