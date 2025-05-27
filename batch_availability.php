
<?php
// batch_availability.php – Viator yêu cầu: mỗi TourDepartureTime là một entry riêng
$body = $GLOBALS['viator_request_data'] ?? [];
$conn = getDB();

if (!isset($body['ApiKey'], $body['ResellerId'], $body['SupplierId'])) {
    jsonError('BatchAvailabilityResponse', $body, 'Authentication failed or invalid request structure');
}

$startDate = $body['StartDate'] ?? date('Y-m-d');
$endDate = $body['EndDate'] ?? $startDate;

$productCodes = [];
$stmt = $conn->prepare("SELECT supplier_product_code, id FROM tblitems WHERE is_active = 1");
$stmt->execute();
$stmt->bind_result($code, $tid);
$items = [];
while ($stmt->fetch()) {
    $productCodes[$code] = $tid;
}
$stmt->close();

$batch = [];
$period = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
);
foreach ($period as $date) {
    $dateStr = $date->format('Y-m-d');

   foreach ($productCodes as $productCode => $tourId) {
        // Lấy option trước bằng get_result()
        $stmtOpt = $conn->prepare("SELECT option_name, departure_time FROM tbl_viator_tour_options WHERE tour_id = ?");
        $stmtOpt->bind_param("i", $tourId);
        $stmtOpt->execute();
        $optResult = $stmtOpt->get_result();
        $stmtOpt->close();

        $options = [];
        while ($opt = $optResult->fetch_assoc()) {
            $options[] = $opt;
        }

        foreach ($options as $opt) {
            // Truy vấn availability theo ngày
            $stmtAvail = $conn->prepare("SELECT version_tag, capacity, booking_cutoff, is_blocked, created_at FROM tbl_viator_availability WHERE supplier_product_code = ? AND start_date = ?");
            $stmtAvail->bind_param("ss", $productCode, $dateStr);
            $stmtAvail->execute();
            $stmtAvail->bind_result($ver, $cap, $cutoff, $blocked, $created_at);

            $hasAvail = $stmtAvail->fetch();
            $stmtAvail->close();

            $status = 'UNAVAILABLE';
            $reason = 'NO_EVENT';

            if ($hasAvail) {
                $now = new DateTime();
                if ((int)$blocked === 1) {
                    $reason = 'BLOCKED_OUT';
                } elseif ($cap <= 0) {
                    $reason = 'SOLD_OUT';
                } elseif ($cutoff && $now > new DateTime($cutoff)) {
                    $reason = 'PAST_CUTOFF_DATE';
                } else {
                    $status = 'AVAILABLE';
                    $reason = null;
                }
            }
    if (!empty($ver) && preg_match('/^v(\d{4}-\d{2}-\d{2})$/', $ver, $match)) {
    $verDate = $match[1];
    if ($verDate >= $dateStr) {
        $timeStamp = (new DateTime($verDate . 'T00:00:00Z'))->format("Y-m-d\\TH:i:s.v\\Z");
    } else {
        $timeStamp = (new DateTime($dateStr . 'T00:00:00Z'))->format("Y-m-d\\TH:i:s.v\\Z");
    }
} elseif (!empty($created_at)) {
    $timeStamp = (new DateTime($created_at))->format("Y-m-d\\TH:i:s.v\\Z");
} else {
    static $offset = 0;
    $timeStamp = (new DateTime())->modify("+{$offset} seconds")->format("Y-m-d\\TH:i:s.v\\Z");
    $offset++;
}

            $batch[] = [
                
                'Date' => $dateStr,
                'SupplierProductCode' => $productCode,
                'TourOptions' => [
                    'TourDepartureTime' => $opt['departure_time']
                ],
                'AvailabilityStatus' => array_filter([
                    'Status' => $status,
                    'UnavailabilityReason' => $reason
                ]),
                'VersionTag' => [
                    'TimeStamp' => $timeStamp
                ]
            ];
        }
    }
}

// Sắp xếp theo ngày
usort($batch, function($a, $b) {
    return strcmp($a['Date'], $b['Date']);
});

$response = [
    'responseType' => 'BatchAvailabilityResponse',
    'data' => [
        'ApiKey' => API_KEY,
        'ResellerId' => RESELLER_ID,
        'SupplierId' => SUPPLIER_ID,
        'ExternalReference' => $body['ExternalReference'] ?? '',
        'Timestamp' => $body['Timestamp'] ?? gmdate("Y-m-d\TH:i:s.v\Z"),
        'RequestStatus' => ['Status' => 'SUCCESS'],
        'BatchTourAvailability' => $batch
    ]
];

echo json_encode($response);
?>
