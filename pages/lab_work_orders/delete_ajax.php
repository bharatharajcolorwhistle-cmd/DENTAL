<?php
/**
 * AJAX Delete Lab Work Order (local application copy only)
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$order_id = isset($input['id']) ? (int) $input['id'] : 0;
$csrf_token = $input['csrf_token'] ?? '';

if (!dcmt_verify_csrf_token($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit();
}

if ($order_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'invalid_work_order_id')]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare('SELECT * FROM dcmt_lab_work_orders WHERE dcmt_id = ? LIMIT 1');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
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

    $deleted = dcmt_lab_delete_local_work_order($dcmt_pdo, $order_id);
    if (!$deleted) {
        echo json_encode(['success' => false, 'message' => trans('lab', 'delete_work_order_failed')]);
        exit();
    }

    $folio = trim((string) ($order['dcmt_folio_number'] ?? ''));
    $log_label = $folio !== '' ? $folio : ('#' . $order_id);
    dcmt_log_activity('Lab work order deleted locally: ' . $log_label, 'lab_work_order_deleted');
    dcmt_show_message(trans('lab', 'work_order_deleted_successfully'), 'success');

    echo json_encode([
        'success' => true,
        'message' => trans('lab', 'work_order_deleted_successfully'),
        'id' => $order_id,
    ]);
} catch (PDOException $e) {
    error_log('Lab work order delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('lab', 'database_error')]);
}
