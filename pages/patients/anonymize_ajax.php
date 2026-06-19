<?php
/**
 * Anonymize patient record (retain ID for financial linkage).
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/patient_compliance.php';

if (!dcmt_is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('patient', 'anonymize_admin_only')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$patient_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$csrf_token = $_POST['csrf_token'] ?? '';

if (!dcmt_verify_csrf_token($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('patient', 'invalid_token')]);
    exit;
}

if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('patient', 'invalid_id')]);
    exit;
}

$result = dcmt_anonymize_patient($dcmt_pdo, $patient_id);
echo json_encode([
    'success' => $result['success'],
    'message' => $result['message'],
    'patient_id' => $patient_id,
]);
