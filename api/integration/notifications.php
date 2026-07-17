<?php
/**
 * Inbound lab → clinic notifications webhook.
 *
 * Canonical URL (lab): POST /api/integration/notifications
 * Also accepts:       POST /api/integration/notifications/
 *
 * Auth: X-API-Key (or Authorization: Bearer) matching an active lab connection API key.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lab_functions.php';

header('Content-Type: application/json; charset=utf-8');

function dcmt_integration_get_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0) {
            continue;
        }
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$name] = $value;
    }

    return $headers;
}

function dcmt_integration_extract_api_key(array $headers): string
{
    $normalized = [];
    foreach ($headers as $name => $value) {
        $normalized[strtolower((string) $name)] = $value;
    }

    $authorization = trim((string) ($normalized['authorization'] ?? ''));
    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return trim((string) ($normalized['x-api-key'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit();
}

try {
    dcmt_ensure_lab_tables($dcmt_pdo);
} catch (Throwable $e) {
    error_log('Lab notifications ensure tables failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to initialize notification storage',
    ]);
    exit();
}

$api_key = dcmt_integration_extract_api_key(dcmt_integration_get_request_headers());
if ($api_key === '') {
    $api_key = trim((string) ($_GET['api_key'] ?? ''));
}

if ($api_key === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'API key is required',
    ]);
    exit();
}

$connection = dcmt_lab_find_connection_by_api_key($dcmt_pdo, $api_key);
if (!$connection) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid API key',
    ]);
    exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON body',
    ]);
    exit();
}

$event = trim((string) ($payload['event'] ?? ''));
if ($event === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'event is required',
    ]);
    exit();
}

if ($event !== 'EXTERNAL_VERIFICATION_REQUESTED') {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Event acknowledged but not handled',
        'created' => 0,
    ]);
    exit();
}

try {
    $result = dcmt_lab_create_inbound_notifications($dcmt_pdo, $connection, $payload);
    if (!$result['success']) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Unable to create notifications',
            'created' => (int) ($result['created'] ?? 0),
        ]);
        exit();
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Notifications created',
        'created' => (int) ($result['created'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('Lab inbound notification error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to store notifications',
    ]);
}
