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
$role = $user['dcmt_role'] ?? '';
if (!in_array($role, ['admin', 'doctor'], true) && !dcmt_is_admin()) {
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
$processes = is_array($data['processes'] ?? null) ? $data['processes'] : [];
usort($processes, static function ($a, $b) {
    $sa = is_array($a) ? (int) ($a['sequence'] ?? 0) : 0;
    $sb = is_array($b) ? (int) ($b['sequence'] ?? 0) : 0;
    return $sa <=> $sb;
});

try {
    $update = $dcmt_pdo->prepare("
        UPDATE dcmt_lab_work_orders
        SET dcmt_remote_status = ?,
            dcmt_remote_doctor_id = COALESCE(NULLIF(?, ''), dcmt_remote_doctor_id)
        WHERE dcmt_id = ?
    ");
    $update->execute([
        (string) ($data['status'] ?? ''),
        (string) ($data['doctorId'] ?? ''),
        $order_id,
    ]);
} catch (PDOException $e) {
    error_log('Lab work order status cache update error: ' . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'status' => (string) ($data['status'] ?? ($order['dcmt_remote_status'] ?? '')),
    'updated_at' => (string) ($data['updatedAt'] ?? ''),
    'processes' => $processes,
]);
