
<?php
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/viator_api_error.log');

$body = $GLOBALS['viator_request_data'] ?? [];
$conn = getDB();

$tourList = [];
$stmt = $conn->prepare("SELECT id, supplier_product_code, description, long_description, country_code, destination_code, destination_name FROM tblitems");
$stmt->execute();
$stmt->store_result(); 
$stmt->bind_result($tourId, $productCode, $tourName, $description, $countryCode, $destinationCode, $destinationName);

while ($stmt->fetch()) {
    $tourList[] = [
        'id' => $tourId,
        'code' => $productCode,
        'name' => $tourName,
        'desc' => $description,
        'country' => $countryCode,
        'destCode' => $destinationCode,
        'destName' => $destinationName
    ];
}
$stmt->close();

$tours = [];
foreach ($tourList as $tour) {
    $options = [];
    $optStmt = $conn->prepare("SELECT option_code, option_name, duration, language, departure_time FROM tbl_viator_tour_options WHERE tour_id = ?");
    $optStmt->bind_param("i", $tour['id']);
    $optStmt->execute();
    $optStmt->bind_result($optCode, $optName, $duration, $lang, $depart);

    while ($optStmt->fetch()) {
        $option = [
            'TourDepartureTime' => $depart
        ];
        if (!empty($option['SupplierOptionCode']) || !empty($option['SupplierOptionName']) || !empty($option['TourDepartureTime'])) {
            $options[] = $option;
        }
    }
    $optStmt->close();

    $tours[] = [
        'SupplierProductCode' => $tour['code'],
        'SupplierProductName' => $tour['name'],
        'CountryCode' => strtoupper($tour['country'] ?: 'VN'),
        'DestinationCode' => strtoupper($tour['destCode'] ?: 'PQC'),
        'DestinationName' => $tour['destName'] ?: 'Phu Quoc',
        'TourDescription' => $tour['desc'],
        'TourOption' => $options
    ];
}

$response = [
    'responseType' => 'TourListResponse',
    'data' => [
        'ApiKey' => API_KEY,
        'ResellerId' => RESELLER_ID,
        'SupplierId' => SUPPLIER_ID,
        'ExternalReference' => $body['ExternalReference'] ?? '',
        'Timestamp' => gmdate("Y-m-d\TH:i:s.v\Z"),
        'RequestStatus' => [ 'Status' => 'SUCCESS' ],
        'Tour' => $tours
    ]
];

echo json_encode($response);
?>
