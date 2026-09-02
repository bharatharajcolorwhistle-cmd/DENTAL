<?php
/**
 * AJAX Delete Lab Connection
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

if (!dcmt_can_delete_lab()) {
    http_response_code(403);
    $deny_message = dcmt_is_staff_user()
        ? trans('common', 'staff_cannot_delete')
        : 'Access denied.';
    echo json_encode(['success' => false, 'message' => $deny_message]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

dcmt_ensure_lab_tables($dcmt_pdo);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$connection_id = isset($input['id']) ? (int) $input['id'] : 0;
$csrf_token = $input['csrf_token'] ?? '';

if (!dcmt_verify_csrf_token($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit();
}

if ($connection_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'invalid_id')]);
    exit();
}

try {
    $connection = dcmt_lab_get_connection($dcmt_pdo, $connection_id);
    if (!$connection) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => trans('lab', 'not_found')]);
        exit();
    }

    $stmt = $dcmt_pdo->prepare('SELECT COUNT(*) FROM dcmt_lab_work_orders WHERE dcmt_lab_connection_id = ?');
    $stmt->execute([$connection_id]);
    $order_count = (int) $stmt->fetchColumn();
    if ($order_count > 0) {
        echo json_encode([
            'success' => false,
            'message' => trans('lab', 'cannot_delete_with_orders'),
            'usage_count' => $order_count,
        ]);
        exit();
    }

    $del = $dcmt_pdo->prepare('DELETE FROM dcmt_lab_connections WHERE dcmt_id = ?');
    $del->execute([$connection_id]);

    dcmt_log_activity('Lab connection deleted: ' . $connection['dcmt_name'], 'lab_connection_deleted');
    dcmt_show_message(trans('lab', 'deleted_successfully'), 'success');

    echo json_encode([
        'success' => true,
        'message' => trans('lab', 'deleted_successfully'),
        'id' => $connection_id,
    ]);
} catch (PDOException $e) {
    error_log('Lab connection delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('lab', 'database_error')]);
}
