<?php
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/viator_api_error.log');
header('Content-Type: application/json');

const API_KEY = 'ae3TwJ8XwgkihC9U5KNwXe_QC8zBgRkk7JP0O6Ev5Qk';
const RESELLER_ID = '1000';
const SUPPLIER_ID = '2001120';
const DB_HOST = 'localhost';
const DB_NAME = 'manhphuo_crm_phuquoc';
const DB_USER = 'manhphuo_crm_phuquoc';
const DB_PASS = '@u]yJr{#6@oH';

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
    return $conn;
}

function getResponseType($requestType) {
    switch ($requestType) {
        case 'AvailabilityRequest': return 'AvailabilityResponse';
        case 'BatchAvailabilityRequest': return 'BatchAvailabilityResponse';
        case 'BookingRequest': return 'BookingResponse';
        case 'BookingAmendmentRequest': return 'BookingAmendmentResponse';
        case 'BookingCancellationRequest': return 'BookingCancellationResponse';
        case 'RedemptionRequest': return 'RedemptionResponse';
        case 'TourListRequest': return 'TourListResponse';
        case 'BatchPricingRequest': return 'BatchPricingResponse';
        case 'AvailabilityNotification2Request': return 'AvailabilityNotification2Response';
        default: return 'GenericResponse';
    }
}

function jsonError($requestType, $data, $code, $message, $details = '') {
    echo json_encode([
        'responseType' => getResponseType($requestType),
        'data' => [
            'ApiKey' => $data['ApiKey'] ?? '',
            'ResellerId' => $data['ResellerId'] ?? '',
            'SupplierId' => $data['SupplierId'] ?? '',
            'ExternalReference' => $data['ExternalReference'] ?? '',
            'Timestamp' => $data['Timestamp'] ?? gmdate('c'),
            'RequestStatus' => [
                'Status' => 'ERROR',
                'Error' => [
                    'ErrorCode' => $code,
                    'ErrorMessage' => $message,
                    'ErrorDetails' => $details
                ]
            ]
        ]
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$endpoint = str_replace('api/viator/', '', $uri);
$bodyRaw = json_decode(file_get_contents('php://input'), true);

$requestType = $bodyRaw['requestType'] ?? null;
$data = $bodyRaw['data'] ?? null;

$requestTypeMap = [
    'availability' => 'AvailabilityRequest',
    'booking' => 'BookingRequest',
    'booking-amendment' => 'BookingAmendmentRequest',
    'booking-cancellation' => 'BookingCancellationRequest',
    'booking/redemption' => 'RedemptionRequest',
    'tourlist' => 'TourListRequest',
    'batch-availability' => 'BatchAvailabilityRequest',
    'batch-pricing' => 'BatchPricingRequest',
    'availabilitynotification2' => 'AvailabilityNotification2Request'
];

$matchedType = $requestTypeMap[$endpoint] ?? '';
$requestId = null;

if (!$requestType || !$data) {
    http_response_code(200);
    jsonError($requestType ?? 'GenericRequest', $data ?? [], 'TGDS0001', 'Malformed request');
}

if (!(
    isset($data['ApiKey']) && $data['ApiKey'] === API_KEY &&
    isset($data['ResellerId']) && $data['ResellerId'] === RESELLER_ID &&
    isset($data['SupplierId']) && $data['SupplierId'] === SUPPLIER_ID
)) {
    http_response_code(200);
    jsonError($requestType, $data, 'TGDS0002', 'Authentication error');
}

$conn = getDB();

if ($matchedType) {
    $requestData = json_encode($data);
    $apiKey = $data['ApiKey'] ?? '';
    $resellerId = $data['ResellerId'] ?? '';
    $supplierId = $data['SupplierId'] ?? '';
    $externalRef = $data['ExternalReference'] ?? '';
    $timestamp = $data['Timestamp'] ?? gmdate('c');

    $stmt = $conn->prepare("INSERT INTO tbl_viator_requests (api_key, reseller_id, supplier_id, external_reference, timestamp, request_type, request_data) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sssssss", $apiKey, $resellerId, $supplierId, $externalRef, $timestamp, $matchedType, $requestData);
        if ($stmt->execute()) {
            $requestId = $conn->insert_id;
            $data['RequestId'] = $requestId;
        }
        $stmt->close();
    }
}

$GLOBALS['viator_request_data'] = $data;
$GLOBALS['viator_request_type'] = $matchedType;

switch ($endpoint) {
    case 'availability':
        require_once 'endpoints/availability.php';
        break;
    case 'booking':
        require_once 'endpoints/booking.php';
        break;
    case 'booking-amendment':
        require_once 'endpoints/booking_amendment.php';
        break;
    case 'booking-cancellation':
        require_once 'endpoints/booking_cancellation.php';
        break;
    case 'booking/redemption':
        require_once 'endpoints/booking_redemption.php';
        break;
    case 'tourlist':
        require_once 'endpoints/tourlist.php';
        break;
    case 'batch-availability':
        require_once 'endpoints/batch_availability.php';
        break;
    case 'batch-pricing':
        require_once 'endpoints/batch_pricing.php';
        break;
    case 'availabilitynotification2':
        require_once 'endpoints/availability_notification2.php';
        break;
    default:
        http_response_code(404);
        jsonError($matchedType, $data, 'TGDS0026', 'Invalid request');
}

$conn->close();
