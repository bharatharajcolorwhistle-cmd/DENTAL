<?php
/**
 * Public doctor lookup endpoint for LAP integration.
 *
 * Supported authentication headers:
 * - Authorization: Bearer <doctor-api-key>
 * - X-API-Key: <doctor-api-key>
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
    $authorization = '';
    foreach (['Authorization', 'authorization'] as $header_name) {
        if (!empty($headers[$header_name])) {
            $authorization = trim((string) $headers[$header_name]);
            break;
        }
    }

    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    foreach (['X-API-Key', 'x-api-key'] as $header_name) {
        if (!empty($headers[$header_name])) {
            return trim((string) $headers[$header_name]);
        }
    }

    return '';
}

$headers = dcmt_api_get_request_headers();
$api_key = dcmt_api_extract_key_from_headers($headers);

if ($api_key === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'API key is required',
    ]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT
            u.dcmt_id,
            u.dcmt_username,
            u.dcmt_full_name,
            u.dcmt_email,
            u.dcmt_phone,
            u.dcmt_qualification,
            u.dcmt_specialization_id,
            s.dcmt_name AS specialization_name,
            u.dcmt_status
        FROM dcmt_users u
        LEFT JOIN dcmt_doctor_specializations s
            ON s.dcmt_id = u.dcmt_specialization_id
        WHERE u.dcmt_role = 'doctor'
          AND u.dcmt_status = 'active'
          AND u.dcmt_api_key = ?
        LIMIT 1
    ");
    $stmt->execute([$api_key]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doctor) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Doctor not found',
        ]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'doctor' => [
            'id' => (int) $doctor['dcmt_id'],
            'username' => $doctor['dcmt_username'],
            'full_name' => $doctor['dcmt_full_name'],
            'email' => $doctor['dcmt_email'],
            'phone' => $doctor['dcmt_phone'],
            'qualification' => $doctor['dcmt_qualification'],
            'specialization_id' => !empty($doctor['dcmt_specialization_id']) ? (int) $doctor['dcmt_specialization_id'] : null,
            'specialization_name' => $doctor['specialization_name'] ?? null,
            'status' => $doctor['dcmt_status'],
        ],
    ]);
} catch (PDOException $e) {
    error_log('Doctor API lookup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
    ]);
}
