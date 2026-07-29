<?php
/**
 * Lab Work Orders - AJAX external verification (start / end)
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
    exit();
}

$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';
$user_id = (int) ($user['dcmt_id'] ?? 0);
$user_username = (string) ($user['dcmt_username'] ?? '');
$can_view_all_orders = dcmt_is_admin();
if (!in_array($role, ['admin', 'doctor'], true) && !dcmt_is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

dcmt_ensure_lab_tables($dcmt_pdo);

$order_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$action = trim((string) ($_POST['action'] ?? ''));

if ($order_id <= 0 || !in_array($action, ['start', 'end'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'invalid_work_order_id')]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT w.*, c.dcmt_lab_base_url, c.dcmt_api_key, c.dcmt_clinic_url, c.dcmt_status AS connection_status
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

if (!$can_view_all_orders) {
    // Non-owner doctors can only access orders they created or that are assigned to them.
    $order_doctor_user_id = (int) ($order['dcmt_doctor_user_id'] ?? 0);
    $order_created_by = (string) ($order['dcmt_created_by'] ?? '');
    $allowed = ($order_doctor_user_id > 0 && $order_doctor_user_id === $user_id)
        || ($order_created_by !== '' && $order_created_by === $user_username);
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit();
    }
}

if (($order['connection_status'] ?? '') !== 'active') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'inactive_lab')]);
    exit();
}

$remote_work_order_id = trim((string) ($order['dcmt_remote_work_order_id'] ?? ''));
$remote_doctor_id = trim((string) ($order['dcmt_remote_doctor_id'] ?? ''));
$base_url = (string) ($order['dcmt_lab_base_url'] ?? '');
$api_key = (string) ($order['dcmt_api_key'] ?? '');
$clinic_url = trim((string) ($order['dcmt_clinic_url'] ?? ''));
if ($clinic_url === '') {
    $clinic_url = dcmt_lab_default_clinic_url();
}

$post_clinic_url = trim((string) ($_POST['clinicUrl'] ?? ''));
$post_doctor_id = trim((string) ($_POST['doctorId'] ?? ''));
$post_work_order_id = trim((string) ($_POST['workOrderId'] ?? ''));

if ($remote_work_order_id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'verification_missing_ids')]);
    exit();
}

if ($post_work_order_id !== '' && $post_work_order_id !== $remote_work_order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'invalid_work_order_id')]);
    exit();
}

// Resolve missing doctor id from live lab status / notification (needed for start-verification)
if ($remote_doctor_id === '') {
    $status_api = dcmt_lab_fetch_work_order_status($base_url, $api_key, $remote_work_order_id);
    $status_data = ($status_api['success'] && is_array($status_api['data'])) ? $status_api['data'] : null;
    $remote_doctor_id = dcmt_lab_resolve_remote_doctor_id($dcmt_pdo, $order, $status_data);
    if ($remote_doctor_id !== '') {
        try {
            $fix = $dcmt_pdo->prepare("
                UPDATE dcmt_lab_work_orders
                SET dcmt_remote_doctor_id = ?
                WHERE dcmt_id = ?
            ");
            $fix->execute([$remote_doctor_id, $order_id]);
        } catch (PDOException $e) {
            error_log('Lab verification doctor id sync error: ' . $e->getMessage());
        }
    }
}

if ($remote_doctor_id === '' && $post_doctor_id !== '') {
    $remote_doctor_id = $post_doctor_id;
}
if ($post_clinic_url !== '') {
    $clinic_url = $post_clinic_url;
}

if ($action === 'start') {
    if ($remote_doctor_id === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => trans('lab', 'verification_missing_ids')]);
        exit();
    }

    $api = dcmt_lab_start_verification($base_url, $api_key, $clinic_url, $remote_doctor_id, $remote_work_order_id);

    if (!$api['success']) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => dcmt_lab_extract_error_message($api, trans('lab', 'verification_start_failed')),
        ]);
        exit();
    }

    $started_at = date('Y-m-d H:i:s');
    $started_at_ts = time();
    try {
        $update = $dcmt_pdo->prepare("
            UPDATE dcmt_lab_work_orders
            SET dcmt_verification_started_at = ?, dcmt_verification_ended_at = NULL, dcmt_verification_outcome = NULL
            WHERE dcmt_id = ?
        ");
        $update->execute([$started_at, $order_id]);
    } catch (PDOException $e) {
        error_log('Lab verification start save error: ' . $e->getMessage());
    }

    dcmt_lab_dismiss_verification_notifications_for_order($dcmt_pdo, $order_id);

    echo json_encode([
        'success' => true,
        'message' => (string) (($api['data']['message'] ?? '') ?: trans('lab', 'verification_started')),
        'started_at' => $started_at,
        'started_at_ts' => $started_at_ts,
        'verification_started' => true,
        'verification_requested' => false,
    ]);
    exit();
}

// action === 'end'
$outcome = strtoupper(trim((string) ($_POST['outcome'] ?? '')));
$notes = trim((string) ($_POST['notes'] ?? ''));

if (!in_array($outcome, ['SUCCESS', 'FAILURE'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'verification_outcome_required')]);
    exit();
}

if ($outcome === 'FAILURE' && $notes === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'verification_notes_required_failure')]);
    exit();
}

if (empty($order['dcmt_verification_started_at'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'verification_not_started')]);
    exit();
}

$api = dcmt_lab_submit_verification_with_start(
    $base_url,
    $api_key,
    $clinic_url,
    $remote_doctor_id,
    $remote_work_order_id,
    $outcome,
    $notes
);

if (!$api['success']) {
    $lab_payload = is_array($api['lab_payload'] ?? null)
        ? $api['lab_payload']
        : dcmt_lab_build_verify_payload(
            $clinic_url,
            $remote_work_order_id,
            $outcome,
            $notes
        );

    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => dcmt_lab_extract_error_message($api, trans('lab', 'verification_end_failed')),
        'lab_request' => $lab_payload,
    ]);
    exit();
}

try {
    $update = $dcmt_pdo->prepare("
        UPDATE dcmt_lab_work_orders
        SET dcmt_verification_started_at = NULL, dcmt_verification_ended_at = ?, dcmt_verification_outcome = ?
        WHERE dcmt_id = ?
    ");
    $update->execute([date('Y-m-d H:i:s'), $outcome, $order_id]);
} catch (PDOException $e) {
    error_log('Lab verification end save error: ' . $e->getMessage());
}

dcmt_lab_dismiss_verification_notifications_for_order($dcmt_pdo, $order_id);

echo json_encode([
    'success' => true,
    'message' => (string) (($api['data']['message'] ?? '') ?: trans('lab', 'verification_submitted')),
    'outcome' => $outcome,
    'verification_started' => false,
    'verification_requested' => false,
    'lab_request' => is_array($api['lab_payload'] ?? null) ? $api['lab_payload'] : null,
]);
