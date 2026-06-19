<?php
/**
 * Dashboard appointment board AJAX actions (start/end).
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

$current_user = dcmt_get_current_user();
$role = (string)($current_user['dcmt_role'] ?? '');
if (!in_array($role, ['admin', 'staff', 'assistant', 'doctor'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => trans('common', 'unauthorized')
    ]);
    exit();
}

$posted_token = (string)($_POST['csrf_token'] ?? '');
if (!dcmt_verify_csrf_token($posted_token)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => trans('common', 'invalid_token')
    ]);
    exit();
}

$appointment_id = (int)($_POST['appointment_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));

if ($appointment_id <= 0 || !in_array($action, ['start', 'end', 'cancel'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => trans('appointment', 'invalid_request')
    ]);
    exit();
}

try {
    if ($action === 'cancel') {
        $sql = "UPDATE dcmt_appointments SET dcmt_status = 'cancelled' WHERE dcmt_id = ?";
        $params = [$appointment_id];
        $stmt = $dcmt_pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() <= 0) {
            echo json_encode([
                'success' => false,
                'message' => trans('appointment', 'save_failed')
            ]);
            exit();
        }
        echo json_encode([
            'success' => true,
            'message' => trans('appointment', 'cancel_success')
        ]);
        exit();
    }

    $now = dcmt_get_current_datetime();
    $params = [$now, $appointment_id];

    if ($action === 'start') {
        $stmt = $dcmt_pdo->prepare("
            UPDATE dcmt_appointments
            SET dcmt_actual_start_at = ?
            WHERE dcmt_id = ?
              AND dcmt_status NOT IN ('cancelled', 'no_show')
              AND dcmt_actual_start_at IS NULL
        ");
    } else {
        $stmt = $dcmt_pdo->prepare("
            UPDATE dcmt_appointments
            SET dcmt_actual_end_at = ?, dcmt_status = 'completed'
            WHERE dcmt_id = ?
              AND dcmt_status NOT IN ('cancelled', 'no_show')
              AND dcmt_actual_start_at IS NOT NULL
              AND dcmt_actual_end_at IS NULL
        ");
    }

    $stmt->execute($params);
    if ($stmt->rowCount() <= 0) {
        echo json_encode([
            'success' => false,
            'message' => trans('appointment', 'save_failed')
        ]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => trans('appointment', 'update_success')
    ]);
    exit();
} catch (PDOException $e) {
    error_log('Appointment board AJAX action error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => trans('appointment', 'database_error')
    ]);
    exit();
}

