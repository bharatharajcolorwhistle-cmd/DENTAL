<?php
/**
 * Appointment calendar timer actions.
 *
 * Starts (initiate) or finishes (terminate) an appointment and returns the
 * authoritative state so every connected device can converge on the same
 * values. Transitions are serialized with a row lock inside
 * dcmt_appointment_timer_transition(), so the same appointment can never be
 * started or ended twice from different devices.
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

$m = dcmt_appointment_messages();
$current_user = dcmt_get_current_user();
$role = (string)($current_user['dcmt_role'] ?? '');

if (!(dcmt_is_admin() || in_array($role, ['staff', 'assistant', 'doctor'], true))) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'code' => 'forbidden',
        'message' => $m['unauthorized']
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code' => 'invalid_action',
        'message' => $m['invalid_request']
    ]);
    exit();
}

if (!dcmt_verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'invalid_token',
        'message' => $m['invalid_request']
    ]);
    exit();
}

$appointment_id = (int)($_POST['appointment_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$expected_token = trim((string)($_POST['state_token'] ?? ''));

if ($appointment_id <= 0 || !in_array($action, ['start', 'end'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'invalid_action',
        'message' => $m['invalid_request']
    ]);
    exit();
}

// Doctors may only run the clock on their own appointments.
$doctor_guard = 0;
if ($role === 'doctor' && !dcmt_is_admin()) {
    $doctor_guard = (int)($current_user['dcmt_id'] ?? 0);
}

$result = dcmt_appointment_timer_transition($dcmt_pdo, $appointment_id, $action, [
    'expected_token' => $expected_token,
    'doctor_id' => $doctor_guard,
    'action_by' => (int)($current_user['dcmt_id'] ?? 0),
    'actor_name' => (string)($current_user['dcmt_full_name'] ?? ''),
    'actor_role' => $role,
    'source' => 'appointment_calendar',
]);

if ($result['code'] === 'forbidden') {
    http_response_code(403);
} elseif ($result['code'] === 'db_error') {
    http_response_code(500);
}

echo json_encode([
    'success' => (bool)$result['ok'],
    'code' => (string)$result['code'],
    'action' => $action,
    'changed' => (bool)$result['changed'],
    'message' => (string)$result['message'],
    'state' => $result['state'],
    'appointment' => $result['state'],
]);
