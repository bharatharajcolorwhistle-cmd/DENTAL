<?php
/**
 * Lab Work Orders - AJAX status refresh
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$order_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($order_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'invalid_work_order_id')]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT w.*, c.dcmt_lab_base_url, c.dcmt_api_key, c.dcmt_status AS connection_status
        FROM dcmt_lab_work_orders w
        INNER JOIN dcmt_lab_connections c ON c.dcmt_id = w.dcmt_lab_connection_id
        WHERE w.dcmt_id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $order = false;
}

if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => trans('lab', 'work_order_not_found')]);
    exit();
}

if (!dcmt_lab_user_can_access_work_order($order, $user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

if (($order['connection_status'] ?? '') !== 'active') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'inactive_lab')]);
    exit();
}

$remote_work_order_id = trim((string) ($order['dcmt_remote_work_order_id'] ?? ''));
if ($remote_work_order_id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'invalid_work_order_id')]);
    exit();
}

$api = dcmt_lab_fetch_work_order_status(
    (string) ($order['dcmt_lab_base_url'] ?? ''),
    (string) ($order['dcmt_api_key'] ?? ''),
    $remote_work_order_id
);

if (!$api['success'] || !is_array($api['data'])) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => dcmt_lab_extract_error_message($api, 'Failed to fetch lab status'),
    ]);
    exit();
}

$data = $api['data'];
$processes = dcmt_lab_normalize_processes(
    is_array($data['processes'] ?? null) ? $data['processes'] : []
);

$api_doctor_id = trim((string) ($data['doctorId'] ?? ''));

try {
    $update = $dcmt_pdo->prepare("
        UPDATE dcmt_lab_work_orders
        SET dcmt_remote_status = ?,
            dcmt_remote_doctor_id = COALESCE(NULLIF(?, ''), dcmt_remote_doctor_id)
        WHERE dcmt_id = ?
    ");
    $update->execute([
        (string) ($data['status'] ?? ''),
        $api_doctor_id,
        $order_id,
    ]);
} catch (PDOException $e) {
    error_log('Lab work order status cache update error: ' . $e->getMessage());
}

$remote_doctor_id = $api_doctor_id !== ''
    ? $api_doctor_id
    : trim((string) ($order['dcmt_remote_doctor_id'] ?? ''));
$verification_started = !empty($order['dcmt_verification_started_at']);
$verification_requested = dcmt_lab_has_active_verification_request($dcmt_pdo, $order_id);
$verification_completed = !empty($order['dcmt_verification_ended_at'])
    && empty($order['dcmt_verification_started_at'])
    && !$verification_requested;
$can_verify = ($order['connection_status'] ?? '') === 'active'
    && $remote_work_order_id !== ''
    && $remote_doctor_id !== '';

$started_at = !empty($order['dcmt_verification_started_at'])
    ? (string) $order['dcmt_verification_started_at']
    : null;
$started_at_ts = $started_at ? (int) strtotime($started_at) : null;

echo json_encode([
    'success' => true,
    'status' => (string) ($data['status'] ?? ($order['dcmt_remote_status'] ?? '')),
    'updated_at' => (string) ($data['updatedAt'] ?? ''),
    'processes' => $processes,
    'can_verify' => $can_verify,
    'verification_started' => $verification_started,
    'verification_requested' => $verification_requested,
    'verification_completed' => $verification_completed,
    'started_at' => $started_at,
    'started_at_ts' => $started_at_ts,
    'remote_doctor_id' => $remote_doctor_id,
    'remote_work_order_id' => $remote_work_order_id,
    'clinic_url' => trim((string) ($order['dcmt_clinic_url'] ?? '')) !== ''
        ? trim((string) $order['dcmt_clinic_url'])
        : dcmt_lab_default_clinic_url(),
]);
