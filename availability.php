<?php
// availability.php – Viator yêu cầu: mỗi giờ (TourOption) là 1 TourAvailability entry riêng biệt
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/viator_api_error.log');

$body = $GLOBALS['viator_request_data'] ?? [];
$conn = getDB();

if (!isset($body['SupplierProductCode'], $body['StartDate'])) {
    jsonError('AvailabilityResponse', $body, 'Missing required fields');
}

$productCode = $body['SupplierProductCode'];
$startDate = $body['StartDate'];
$totalTravellers = (int)($body['TravellerMix']['Total'] ?? 0);

$stmt = $conn->prepare("SELECT a.capacity, a.version_tag, a.created_at, a.booking_cutoff, a.is_blocked,
       t.is_active, t.currency_code,
       r.adult_allowed, r.child_allowed, r.youth_allowed, r.infant_allowed, r.senior_allowed
FROM tbl_viator_availability a
LEFT JOIN tblitems t ON a.supplier_product_code = t.supplier_product_code
LEFT JOIN tbl_viator_traveller_restrictions r ON r.tour_id = t.id
WHERE a.supplier_product_code = ? AND a.start_date = ?");
$stmt->bind_param("ss", $productCode, $startDate);
$stmt->execute();
$stmt->bind_result($capacity, $versionTag, $created_at, $cutoff, $isBlocked,
                   $isActive, $currencyCode,
                   $a, $c, $y, $i, $s);
$hasAvailability = $stmt->fetch();
$stmt->close();

$currentTime = new DateTime();
$tourAvailability = [];
$travellerMixAvailability = [
    'Adult' => (bool)$a,
    'Child' => (bool)$c,
    'Youth' => (bool)$y,
    'Infant' => (bool)$i,
    'Senior' => (bool)$s
];
$consumedBy = [];
if (!empty($a)) $consumedBy[] = 'ADULT';
if (!empty($c)) $consumedBy[] = 'CHILD';
if (!empty($y)) $consumedBy[] = 'YOUTH';
if (!empty($i)) $consumedBy[] = 'INFANT';
if (!empty($s)) $consumedBy[] = 'SENIOR';

$stmtOpt = $conn->prepare("SELECT option_code, option_name, departure_time FROM tbl_viator_tour_options WHERE tour_id = (SELECT id FROM tblitems WHERE supplier_product_code = ?)");
$stmtOpt->bind_param("s", $productCode);
$stmtOpt->execute();
$stmtOpt->bind_result($optCode, $optName, $optTime);

static $offset = 0;
$requestedTime = $body['TourOptions']['TourDepartureTime'] ?? null;
$hasHold = isset($body['AvailabilityHold']);

while ($stmtOpt->fetch()) {
    if ($requestedTime && $optTime !== $requestedTime) continue;

    $status = 'UNAVAILABLE';
    $reason = 'NO_EVENT';

    if ($hasAvailability) {
        if ((int)$isActive === 0) {
            $reason = 'INACTIVE';
        } elseif ((int)$isBlocked === 1) {
            $reason = 'BLOCKED_OUT';
        } elseif ($capacity < $totalTravellers) {
            $reason = 'TRAVELLER_MISMATCH';
        } elseif ($cutoff && $currentTime > new DateTime($cutoff)) {
            $reason = 'PAST_CUTOFF_DATE';
        } else {
            $status = 'AVAILABLE';
            $reason = null;
        }

        if (isset($body['TravellerMix'])) {
            foreach ($body['TravellerMix'] as $type => $count) {
                if ($count > 0 && isset($travellerMixAvailability[$type]) && !$travellerMixAvailability[$type]) {
                    $status = 'UNAVAILABLE';
                    $reason = 'TRAVELLER_MISMATCH';
                    break;
                }
            }
        }
    }

    if (!empty($versionTag) && preg_match('/^v(\d{4}-\d{2}-\d{2})$/', $versionTag, $match)) {
        $verDate = $match[1];
        $timestamp = (new DateTime($verDate . 'T00:00:00Z'))->format("Y-m-d\TH:i:s.v\Z");
    } elseif (!empty($created_at)) {
        $timestamp = (new DateTime($created_at))->format("Y-m-d\TH:i:s.v\Z");
    } else {
        $timestamp = (new DateTime())->modify("+{$offset} seconds")->format("Y-m-d\TH:i:s.v\Z");
        $offset++;
    }

    $entry = [
        'Date' => $startDate,
        'AvailabilityStatus' => array_filter([
            'Status' => $status,
            'UnavailabilityReason' => $status === 'UNAVAILABLE' ? $reason : null,
            'TravellerMixAvailability' => $travellerMixAvailability
        ]),
        'TourOptions' => [ 'TourDepartureTime' => $optTime ]
    ];
    if ($status === 'UNAVAILABLE' && $reason === 'NO_EVENT') {
    unset($entry['AvailabilityStatus']['TravellerMixAvailability']);
    }
    if ($status !== 'UNAVAILABLE' || $reason !== 'NO_EVENT') {
        $entry['BookingCutoff'] = ['ProductDateTime' => date('Y-m-d\TH:i:s', strtotime($cutoff))];
        $entry['Price'] = []; // Tạm để, gắn giá sau
        $entry['Capacity'] = [
            'Simple' => [
                'Remaining' => $capacity,
                'ConsumedBy' => $consumedBy
            ]
        ];
        $entry['VersionTag'] = ['TimeStamp' => $timestamp];
        if ($hasHold) {
            $entry['AvailabilityHold'] = [
                'Expiry' => $body['AvailabilityHold']['Expiry'] ?? 'PT300S',
                'Reference' => 'HOLD-' . rand(1000000, 9999999)
            ];
        }
    }

    $tourAvailability[] = $entry;
}
$stmtOpt->close();

$pricing = [];
$stmtP = $conn->prepare("SELECT retail_price, age_band FROM tbl_viator_pricing WHERE supplier_product_code = ?");
$stmtP->bind_param("s", $productCode);
$stmtP->execute();
$stmtP->bind_result($retail_price, $age_band);
while ($stmtP->fetch()) {
    $pricing[] = [
        'RetailPrice' => number_format((float)$retail_price, 2, '.', ''),
        'AgeBand' => $age_band
    ];
}
$stmtP->close();

foreach ($tourAvailability as &$t) {
    if (isset($t['Price'])) {
        $t['Price'] = [
            'CurrencyCode' => $currencyCode,
            'Item' => $pricing
        ];
    }
    if (isset($t['AvailabilityHold']) && empty($t['AvailabilityHold'])) {
        unset($t['AvailabilityHold']);
    }
}

$response = [
    'responseType' => 'AvailabilityResponse',
    'data' => [
        'ApiKey' => API_KEY,
        'ResellerId' => RESELLER_ID,
        'SupplierId' => SUPPLIER_ID,
        'ExternalReference' => $body['ExternalReference'] ?? '',
        'Timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
        'RequestStatus' => ['Status' => 'SUCCESS'],
        'SupplierProductCode' => $productCode,
        'TourAvailability' => $tourAvailability
    ]
];

echo json_encode($response);
