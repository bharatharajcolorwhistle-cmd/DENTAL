<?php
/**
 * Lab Connections - AJAX payments (pending amount summary + work order payment list)
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

$user = dcmt_get_current_user();
if (!dcmt_can_access_lab($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

dcmt_ensure_lab_tables($dcmt_pdo);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$action = trim((string) ($_GET['action'] ?? 'work_orders'));
$connection_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($connection_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'invalid_id')]);
    exit();
}

$connection = dcmt_lab_get_connection($dcmt_pdo, $connection_id);
if (!$connection) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => trans('lab', 'not_found')]);
    exit();
}

if (($connection['dcmt_status'] ?? '') !== 'active') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('lab', 'inactive_lab')]);
    exit();
}

$base_url = (string) ($connection['dcmt_lab_base_url'] ?? '');
$api_key = (string) ($connection['dcmt_api_key'] ?? '');
$clinic_url = trim((string) ($connection['dcmt_clinic_url'] ?? ''));
if ($clinic_url === '') {
    $clinic_url = dcmt_lab_default_clinic_url();
}

if ($action === 'pending') {
    $api = dcmt_lab_fetch_clinic_pending_amount($base_url, $api_key, $clinic_url);
    if (!$api['success'] || !is_array($api['data'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => dcmt_lab_extract_error_message($api, trans('lab', 'payment_load_failed')),
        ]);
        exit();
    }

    $data = $api['data'];
    echo json_encode([
        'success' => true,
        'currency' => (string) ($data['currency'] ?? ''),
        'total_pending_amount' => (float) ($data['totalPendingAmount'] ?? 0),
        'total_collected_amount' => (float) ($data['totalCollectedAmount'] ?? 0),
        'total_quoted_amount' => (float) ($data['totalQuotedAmount'] ?? 0),
        'total_work_orders' => (int) ($data['totalWorkOrders'] ?? 0),
        'pending_work_orders_count' => (int) ($data['pendingWorkOrdersCount'] ?? 0),
        'paid_work_orders_count' => (int) ($data['paidWorkOrdersCount'] ?? 0),
    ]);
    exit();
}

if ($action === 'work_orders') {
    $api = dcmt_lab_fetch_clinic_work_orders($base_url, $api_key, $clinic_url);
    if (!$api['success'] || !is_array($api['data'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => dcmt_lab_extract_error_message($api, trans('lab', 'payment_load_failed')),
        ]);
        exit();
    }

    $data = $api['data'];
    $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
    $raw_orders = is_array($data['workOrders'] ?? null) ? $data['workOrders'] : [];

    $work_orders = [];
    foreach ($raw_orders as $row) {
        if (!is_array($row)) {
            continue;
        }

        $patient = $row['patient'] ?? null;
        if (is_array($patient)) {
            $patient = trim((string) ($patient['name'] ?? ($patient['fullName'] ?? '')));
        } else {
            $patient = trim((string) $patient);
        }
        if ($patient === '') {
            $patient = trim((string) ($row['patientName'] ?? ''));
        }

        $work_orders[] = [
            'id' => trim((string) ($row['id'] ?? '')),
            'folio_number' => trim((string) ($row['folioNumber'] ?? '')),
            'patient' => $patient,
            'total_quote' => (float) ($row['totalQuote'] ?? 0),
            'collected_amount' => (float) ($row['collectedAmount'] ?? 0),
            'pending_amount' => (float) ($row['pendingAmount'] ?? 0),
            'status' => trim((string) ($row['status'] ?? '')),
        ];
    }

    echo json_encode([
        'success' => true,
        'clinic' => is_array($data['clinic'] ?? null) ? $data['clinic'] : [],
        'currency' => (string) ($data['currency'] ?? ''),
        'summary' => [
            'total_orders' => (int) ($summary['totalOrders'] ?? count($work_orders)),
            'total_quote' => (float) ($summary['totalQuote'] ?? 0),
            'total_collected' => (float) ($summary['totalCollected'] ?? 0),
            'total_pending' => (float) ($summary['totalPending'] ?? 0),
        ],
        'work_orders' => $work_orders,
    ]);
    exit();
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action']);
