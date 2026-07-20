<?php
/**
 * Lab Work Orders - AJAX chat messages (list / send)
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';
if (!in_array($role, ['admin', 'doctor'], true) && !dcmt_is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

dcmt_ensure_lab_tables($dcmt_pdo);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'list'));
$user_id = (int) ($user['dcmt_id'] ?? 0);

// Unread chat dots for index page (no single work-order required)
if ($action === 'unread') {
    $ids_raw = trim((string) ($_GET['ids'] ?? $_POST['ids'] ?? ''));
    $order_ids = [];
    if ($ids_raw !== '') {
        foreach (explode(',', $ids_raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $order_ids[] = $id;
            }
        }
    }
    $counts = dcmt_lab_chat_unread_counts_by_order($dcmt_pdo, $user_id, $order_ids);
    // JSON object keys as strings for stable JS consumption
    $payload = [];
    foreach ($counts as $oid => $count) {
        $payload[(string) $oid] = (int) $count;
    }
    echo json_encode([
        'success' => true,
        'unread' => $payload,
    ]);
    exit();
}

$order_id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

if ($order_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'invalid_work_order_id')]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT w.*, c.dcmt_lab_base_url, c.dcmt_api_key, c.dcmt_status AS connection_status,
               c.dcmt_name AS lab_connection_name, c.dcmt_lab_organization, c.dcmt_lab_remote_name
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
    echo json_encode(['success' => false, 'message' => trans('lab', 'chat_missing_remote_id')]);
    exit();
}

$base_url = (string) ($order['dcmt_lab_base_url'] ?? '');
$api_key = (string) ($order['dcmt_api_key'] ?? '');
$remote_doctor_id = trim((string) ($order['dcmt_remote_doctor_id'] ?? ''));

if ($action === 'list' || ($method === 'GET' && $action === '')) {
    $api = dcmt_lab_fetch_work_order_messages($base_url, $api_key, $remote_work_order_id);
    if (!$api['success'] || !is_array($api['data'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => dcmt_lab_extract_error_message($api, trans('lab', 'chat_load_failed')),
        ]);
        exit();
    }

    $data = $api['data'];
    $conversation = is_array($data['conversation'] ?? null) ? $data['conversation'] : [];
    $participants = is_array($conversation['participants'] ?? null) ? $conversation['participants'] : [];
    $clinic_doctor_name = trim((string) ($order['dcmt_doctor_name'] ?? ''));
    $clinic_sender_ids = dcmt_lab_chat_clinic_sender_ids($participants, $remote_doctor_id);
    $participant_roles = dcmt_lab_chat_participant_role_map($participants);
    $raw_messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
    $messages = [];
    foreach ($raw_messages as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $messages[] = dcmt_lab_normalize_chat_message(
            $raw,
            $remote_doctor_id,
            $clinic_sender_ids,
            $clinic_doctor_name,
            $participant_roles
        );
    }

    if ($user_id > 0) {
        dcmt_lab_dismiss_chat_notifications_for_order($dcmt_pdo, $user_id, $order_id);
    }

    $folio = trim((string) ($order['dcmt_folio_number'] ?? ''));
    $conversation_name = trim((string) ($conversation['name'] ?? ''));
    if ($conversation_name === '') {
        $conversation_name = $folio !== ''
            ? (trans('lab', 'work_order_chat') . ' — ' . $folio)
            : trans('lab', 'work_order_chat');
    }

    echo json_encode([
        'success' => true,
        'order' => [
            'id' => $order_id,
            'folio_number' => $folio,
            'patient_name' => (string) ($order['dcmt_patient_name'] ?? ''),
            'lab_name' => dcmt_lab_connection_display_name($order),
        ],
        'conversation' => [
            'id' => (string) ($conversation['id'] ?? ''),
            'name' => $conversation_name,
            'participants' => $participants,
            'participant_names' => dcmt_lab_chat_participant_names($participants),
        ],
        'messages' => $messages,
        'has_more' => !empty($data['hasMore']),
    ]);
    exit();
}

if ($action === 'send') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }

    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
        exit();
    }

    $content = trim((string) ($_POST['content'] ?? ''));
    if ($content === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => trans('lab', 'chat_empty_message')]);
        exit();
    }

    if ((function_exists('mb_strlen') ? mb_strlen($content) : strlen($content)) > 4000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => trans('lab', 'chat_message_too_long')]);
        exit();
    }

    $api = dcmt_lab_send_work_order_message($base_url, $api_key, $remote_work_order_id, $content);
    if (!$api['success'] || !is_array($api['data'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => dcmt_lab_extract_error_message($api, trans('lab', 'chat_send_failed')),
        ]);
        exit();
    }

    $msg = dcmt_lab_normalize_chat_message(
        $api['data'],
        $remote_doctor_id,
        dcmt_lab_chat_clinic_sender_ids([], $remote_doctor_id),
        trim((string) ($order['dcmt_doctor_name'] ?? ''))
    );
    $msg['is_mine'] = true;

    echo json_encode([
        'success' => true,
        'message' => $msg,
    ]);
    exit();
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action']);
