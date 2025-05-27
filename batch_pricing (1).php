
<?php
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/viator_api_error.log');

$body = $GLOBALS['viator_request_data'] ?? [];
$conn = getDB();

if (!isset($body['SupplierProductCode'], $body['StartDate'], $body['EndDate'])) {
    jsonError('BatchPricingResponse', $body, 'Missing required fields');
}

$productCodes = $body['SupplierProductCode'];
$startDate = $body['StartDate'];
$endDate = $body['EndDate'];

if (!is_array($productCodes) || count($productCodes) === 0) {
    jsonError('BatchPricingResponse', $body, 'Missing or invalid SupplierProductCode array');
}

// Lấy tour info
$items = [];
$stmt = $conn->prepare("SELECT supplier_product_code, id, currency_code FROM tblitems WHERE supplier_product_code IN (" . implode(',', array_fill(0, count($productCodes), '?')) . ")");
$stmt->bind_param(str_repeat('s', count($productCodes)), ...$productCodes);
$stmt->execute();
$stmt->bind_result($code, $tourId, $currency);
while ($stmt->fetch()) {
    $items[$code] = ['id' => $tourId, 'currency' => $currency];
}
$stmt->close();

// Lặp từng ngày và từng sản phẩm × option
$period = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
);

$grouped = [];
foreach ($period as $date) {
    $dateStr = $date->format('Y-m-d');
    foreach ($items as $productCode => $info) {
        $tourId = $info['id'];
        $currencyCode = $info['currency'];

        // Lấy danh sách options
        $stmtOpt = $conn->prepare("SELECT option_code, option_name, departure_time FROM tbl_viator_tour_options WHERE tour_id = ?");
        $stmtOpt->bind_param("i", $tourId);
        $stmtOpt->execute();
        $optResult = $stmtOpt->get_result();
        $stmtOpt->close();

        while ($opt = $optResult->fetch_assoc()) {
            $key = $productCode . '|' . $dateStr . '|' . $opt['departure_time'];

            $grouped[$key] = [
                'Date' => $dateStr,
                'SupplierProductCode' => $productCode,
                'TourOptions' => [
                    'TourDepartureTime' => $opt['departure_time']
                ],
                'BatchPrice' => ['Item' => []],
                'AvailabilityStatus' => ['Status' => 'UNAVAILABLE', 'UnavailabilityReason' => 'NO_EVENT']
            ];

            // Load pricing
            $stmtPrice = $conn->prepare("SELECT age_band, retail_price FROM tbl_viator_pricing WHERE supplier_product_code = ?");
            $stmtPrice->bind_param("s", $productCode);
            $stmtPrice->execute();
            $stmtPrice->bind_result($age, $price);
            while ($stmtPrice->fetch()) {
                $grouped[$key]['BatchPrice']['Item'][] = [
                    'RetailPrice' => (float)$price,
                    'AgeBand' => $age
                ];
            }
            $stmtPrice->close();

            // Load availability
            $stmtAvail = $conn->prepare("SELECT capacity, booking_cutoff, is_blocked, is_active FROM tbl_viator_availability a LEFT JOIN tblitems t ON a.supplier_product_code = t.supplier_product_code WHERE a.supplier_product_code = ? AND a.start_date = ?");
            $stmtAvail->bind_param("ss", $productCode, $dateStr);
            $stmtAvail->execute();
            $stmtAvail->bind_result($cap, $cutoff, $blocked, $active);
            if ($stmtAvail->fetch()) {
                $now = new DateTime();
                $status = 'AVAILABLE';
                $reason = null;

                if ((int)$active === 0) {
                    $status = 'UNAVAILABLE';
                    $reason = 'INACTIVE';
                } elseif ((int)$blocked === 1) {
                    $status = 'UNAVAILABLE';
                    $reason = 'BLOCKED_OUT';
                } elseif ((int)$cap <= 0) {
                    $status = 'UNAVAILABLE';
                    $reason = 'SOLD_OUT';
                } elseif (!empty($cutoff) && $now > new DateTime($cutoff)) {
                    $status = 'UNAVAILABLE';
                    $reason = 'PAST_CUTOFF_DATE';
                }

                $grouped[$key]['AvailabilityStatus'] = array_filter([
                    'Status' => $status,
                    'UnavailabilityReason' => $reason
                ]);
            }
            $stmtAvail->close();
        }
    }
}

ksort($grouped);

$response = [
    'responseType' => 'BatchPricingResponse',
    'data' => [
        'ApiKey' => API_KEY,
        'ResellerId' => RESELLER_ID,
        'SupplierId' => SUPPLIER_ID,
        'ExternalReference' => $body['ExternalReference'] ?? '',
        'Timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
        'RequestStatus' => [ 'Status' => 'SUCCESS' ],
        'CurrencyCode' => $currencyCode ?? 'USD',
        'BatchTourPricing' => array_values($grouped)
    ]
];

echo json_encode($response);
?>
