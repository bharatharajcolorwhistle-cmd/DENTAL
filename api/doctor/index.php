<?php
/**
 * Public doctor lookup endpoint for LAP integration.
 *
 * Supported authentication headers:
 * - Authorization: Bearer <doctor-api-key>
 * - X-API-Key: <doctor-api-key>
 *
 * One API key may be assigned to multiple doctors. This endpoint returns
 * all doctor records stored with the provided API key.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit();
}

function dcmt_api_get_request_headers(): array
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

function dcmt_api_extract_key_from_headers(array $headers): string
{
    $normalized_headers = [];
    foreach ($headers as $name => $value) {
        $normalized_headers[strtolower((string) $name)] = $value;
    }

    $authorization = trim((string) ($normalized_headers['authorization'] ?? ''));
    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    $api_key_header = trim((string) ($normalized_headers['x-api-key'] ?? ''));
    if ($api_key_header !== '') {
        return $api_key_header;
    }

    return '';
}

$headers = dcmt_api_get_request_headers();
$api_key = dcmt_api_extract_key_from_headers($headers);

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

try {
    $clinic_name = dcmt_get_site_name();

    $stmt = $dcmt_pdo->prepare("
        SELECT
            u.dcmt_id,
            u.dcmt_full_name,
            u.dcmt_email
        FROM dcmt_users u
        WHERE u.dcmt_role = 'doctor'
          AND TRIM(COALESCE(u.dcmt_api_key, '')) = ?
        ORDER BY u.dcmt_full_name ASC, u.dcmt_id ASC
    ");
    $stmt->execute([$api_key]);
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$doctors) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No doctors found for this API key',
        ]);
        exit();
    }

    $doctor_payload = array_map(static function (array $doctor) use ($clinic_name): array {
        return [
            'id' => (int) $doctor['dcmt_id'],
            'full_name' => $doctor['dcmt_full_name'],
            'email' => $doctor['dcmt_email'],
            'clinic_name' => $clinic_name,
        ];
    }, $doctors);

    echo json_encode([
        'success' => true,
        'count' => count($doctor_payload),
        'doctors' => $doctor_payload,
    ]);
} catch (PDOException $e) {
    error_log('Doctor API lookup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
    ]);
}
