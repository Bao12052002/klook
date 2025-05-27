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
        jsonError('GenericRequest', [], 'TGDS0005', 'Service not available', 'Failed to connect to database');
    }
    return $conn;
}

function getResponseType($requestType) {
    return match($requestType) {
        'AvailabilityRequest' => 'AvailabilityResponse',
        'BatchAvailabilityRequest' => 'BatchAvailabilityResponse',
        'BookingRequest' => 'BookingResponse',
        'BookingAmendmentRequest' => 'BookingAmendmentResponse',
        'BookingCancellationRequest' => 'BookingCancellationResponse',
        'RedemptionRequest' => 'RedemptionResponse',
        'TourListRequest' => 'TourListResponse',
        'BatchPricingRequest' => 'BatchPricingResponse',
        'AvailabilityNotification2Request' => 'AvailabilityNotification2Response',
        default => 'GenericResponse',
    };
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
    jsonError('GenericRequest', [], 'TGDS0001', 'Malformed request', 'Only POST requests are allowed');
}

$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$endpoint = str_replace('api/viator/', '', $uri);
$bodyRaw = file_get_contents('php://input');
if (!$bodyRaw) {
    jsonError('GenericRequest', [], 'TGDS0001', 'Malformed request', 'Empty request body');
}

$parsed = json_decode($bodyRaw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    jsonError('GenericRequest', [], 'TGDS0001', 'Malformed request', 'Invalid JSON format');
}

$requestType = $parsed['requestType'] ?? null;
$data = $parsed['data'] ?? null;

if (!$requestType || !$data || !is_array($data)) {
    jsonError('GenericRequest', [], 'TGDS0001', 'Malformed request', 'Missing requestType or data');
}

$requestTypeMap = [
    'availability' => 'AvailabilityRequest',
    'booking' => 'BookingRequest',
    'booking-amendment' => 'BookingAmendmentRequest',
    'booking-cancellation' => 'BookingCancellationRequest',
    'booking/redemption' => 'RedemptionRequest',
    'tourlist' => 'TourListRequest',
    'batch-availability' => 'BatchAvailabilityRequest',
    'batch-pricing' => 'BatchPricingRequest',
    'availability-notification2' => 'AvailabilityNotification2Request',
];

$matchedType = $requestTypeMap[$endpoint] ?? null;
if (!$matchedType) {
    jsonError($requestType, $data, 'TGDS0003', 'Invalid connector', 'Endpoint does not match any supported API');
}

$requiredFields = ['ApiKey', 'ResellerId', 'SupplierId'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        jsonError($matchedType, $data, 'TGDS0022', 'Missing API parameter', "Missing required field: $field");
    }
}

if (
    $data['ApiKey'] !== API_KEY ||
    $data['ResellerId'] !== RESELLER_ID ||
    $data['SupplierId'] !== SUPPLIER_ID
) {
    jsonError($matchedType, $data, 'TGDS0002', 'Authentication error', 'Invalid authentication credentials');
}

// Simulate account inactive or invalid supplier
if ($data['SupplierId'] === '0000000') {
    jsonError($matchedType, $data, 'TGDS0011', 'Invalid supplier', 'Supplier ID is not valid');
}
if ($data['ResellerId'] === '0000') {
    jsonError($matchedType, $data, 'TGDS0015', 'Invalid reseller ID', 'Reseller ID is not allowed');
}
if ($data['ApiKey'] === 'INACTIVE') {
    jsonError($matchedType, $data, 'TGDS0016', 'Inactive account', 'API Key is inactive or disabled');
}

// Endpoint dispatch
try {
    require_once $endpoint . '.php';
} catch (Throwable $e) {
    jsonError($matchedType, $data, 'TGDS0031', 'Unhandled internal error in supplier system', $e->getMessage());
}
