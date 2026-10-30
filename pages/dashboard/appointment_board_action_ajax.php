<?php
/**
 * Dashboard appointment board AJAX actions (start/end).
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

$current_user = dcmt_get_current_user();
$role = (string)($current_user['dcmt_role'] ?? '');
if (!in_array($role, ['super_admin', 'admin', 'staff', 'assistant', 'doctor'], true)) {
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
        $sql = "UPDATE dcmt_appointments
                SET dcmt_status = 'cancelled'
                WHERE dcmt_id = ?
                  AND dcmt_status NOT IN ('cancelled', 'completed', 'no_show')";
        $stmt = $dcmt_pdo->prepare($sql);
        $stmt->execute([$appointment_id]);
        if ($stmt->rowCount() <= 0) {
            echo json_encode([
                'success' => false,
                'code' => 'stale',
                'message' => trans('appointment', 'appointment_state_changed')
            ]);
            exit();
        }
        echo json_encode([
            'success' => true,
            'code' => 'cancelled',
            'message' => trans('appointment', 'cancel_success')
        ]);
        exit();
    }

    // Shared, row-locked transition: two devices can never both start/end the
    // same appointment, and the caller always receives the authoritative state.
    $doctor_guard = 0;
    if ($role === 'doctor' && !dcmt_is_admin()) {
        $doctor_guard = (int)($current_user['dcmt_id'] ?? 0);
    }

    $result = dcmt_appointment_timer_transition($dcmt_pdo, $appointment_id, $action, [
        'expected_token' => (string)($_POST['state_token'] ?? ''),
        'doctor_id' => $doctor_guard,
        'action_by' => (int)($current_user['dcmt_id'] ?? 0),
        'actor_name' => (string)($current_user['dcmt_full_name'] ?? ''),
        'actor_role' => $role,
        'source' => 'appointment_board',
    ]);

    if ($result['code'] === 'db_error') {
        http_response_code(500);
    }

    echo json_encode([
        'success' => (bool)$result['ok'],
        'code' => (string)$result['code'],
        'changed' => (bool)$result['changed'],
        'message' => (string)$result['message'],
        'state' => $result['state'],
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

