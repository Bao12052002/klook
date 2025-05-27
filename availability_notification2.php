
<?php
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/viator_api_error.log');

$body = $GLOBALS['viator_request_data'] ?? [];
$conn = getDB();

// Xác thực tối thiểu
if (!isset($body['ApiKey'], $body['ResellerId'], $body['SupplierId'], $body['VariantAvailability'])) {
    jsonError('AvailabilityNotification2Response', $body, 'Missing required fields');
}

$variantList = $body['VariantAvailability'];
if (!is_array($variantList) || empty($variantList)) {
    jsonError('AvailabilityNotification2Response', $body, 'VariantAvailability must be a non-empty array');
}

$updated = 0;
$errors = [];

foreach ($variantList as $variant) {
    $productCode = $variant['SupplierProductCode'] ?? null;
    $startDate = $variant['StartDate'] ?? null;
    $status = strtoupper($variant['Status'] ?? ''); // AVAILABLE, BLOCKED_OUT, NO_EVENT
    $versionTag = $variant['VersionTag'] ?? '';

    if (!$productCode || !$startDate || !in_array($status, ['AVAILABLE', 'BLOCKED_OUT', 'NO_EVENT'])) {
        $errors[] = [
            'SupplierProductCode' => $productCode,
            'StartDate' => $startDate,
            'Error' => 'Invalid variant input'
        ];
        continue;
    }

    if ($status === 'NO_EVENT') {
        $stmt = $conn->prepare("DELETE FROM tbl_viator_availability WHERE supplier_product_code = ? AND start_date = ?");
        $stmt->bind_param("ss", $productCode, $startDate);
        if ($stmt->execute()) $updated++;
        $stmt->close();
        continue;
    }

    $isBlocked = $status === 'BLOCKED_OUT' ? 1 : 0;
    $stmt = $conn->prepare("SELECT id FROM tbl_viator_availability WHERE supplier_product_code = ? AND start_date = ?");
    $stmt->bind_param("ss", $productCode, $startDate);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        $stmt = $conn->prepare("UPDATE tbl_viator_availability SET is_blocked = ?, version_tag = ? WHERE supplier_product_code = ? AND start_date = ?");
        $stmt->bind_param("isss", $isBlocked, $versionTag, $productCode, $startDate);
    } else {
        $stmt->close();
        $defaultCapacity = 10;
        $stmt = $conn->prepare("INSERT INTO tbl_viator_availability (supplier_product_code, start_date, capacity, is_blocked, version_tag) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiss", $productCode, $startDate, $defaultCapacity, $isBlocked, $versionTag);
    }

    if ($stmt->execute()) $updated++;
    else {
        $errors[] = [
            'SupplierProductCode' => $productCode,
            'StartDate' => $startDate,
            'Errors' => $stmt->error
        ];
    }
    $stmt->close();
}

$response = [
    'responseType' => 'AvailabilityNotification2Response',
    'data' => [
        'ApiKey' => API_KEY,
        'ResellerId' => RESELLER_ID,
        'SupplierId' => SUPPLIER_ID,
        'ExternalReference' => $body['ExternalReference'] ?? '',
        'Timestamp' => gmdate('c'),
        'RequestStatus' => [
            'Status' => 'SUCCESS',
        ]
    ]
];

echo json_encode($response);
?>
