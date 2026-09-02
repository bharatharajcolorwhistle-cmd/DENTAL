<?php
/**
 * Lab Work Orders - AJAX setup (prosthesis types + next folio)
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$user = dcmt_get_current_user();
if (!dcmt_can_access_lab($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

dcmt_ensure_lab_tables($dcmt_pdo);

$lab_id = isset($_REQUEST['lab_id']) ? (int) $_REQUEST['lab_id'] : 0;
if ($lab_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'lab_required')]);
    exit();
}

$connection = dcmt_lab_get_connection($dcmt_pdo, $lab_id);
if (!$connection || $connection['dcmt_status'] !== 'active') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'inactive_lab')]);
    exit();
}

$api = dcmt_lab_fetch_work_order_setup($connection['dcmt_lab_base_url'], $connection['dcmt_api_key']);
if (!$api['success']) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => trans('lab', 'setup_load_failed') . ': ' . dcmt_lab_extract_error_message($api),
    ]);
    exit();
}

$data = is_array($api['data']) ? $api['data'] : [];
$types = is_array($data['prosthesisTypes'] ?? null) ? $data['prosthesisTypes'] : [];

echo json_encode([
    'success' => true,
    'prosthesisTypes' => $types,
    'nextFolioNumber' => $data['nextFolioNumber'] ?? '',
    'labName' => dcmt_lab_connection_display_name($connection),
]);
