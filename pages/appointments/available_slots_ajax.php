<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

header('Content-Type: application/json');

if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

$m = dcmt_appointment_messages();
$doctor_id = (int)($_GET['doctor_id'] ?? 0);
$date = trim((string)($_GET['date'] ?? ''));
$duration = (int)($_GET['duration'] ?? 30);
if ($duration < 5) {
    $duration = 30;
}

if ($doctor_id <= 0) {
    echo json_encode(['success' => false, 'message' => $m['doctor_required']]);
    exit();
}
if ($date === '') {
    echo json_encode(['success' => false, 'message' => $m['date_required']]);
    exit();
}

try {
    if (!dcmt_is_doctor_user($dcmt_pdo, $doctor_id)) {
        echo json_encode(['success' => false, 'message' => $m['doctor_invalid']]);
        exit();
    }

    $duty_ranges = dcmt_get_doctor_duty_ranges($dcmt_pdo, $doctor_id, $date);
    if (empty($duty_ranges)) {
        echo json_encode(['success' => false, 'message' => $m['doctor_unavailable_day'], 'slots' => []]);
        exit();
    }

    $busy_slots = dcmt_get_busy_slots($dcmt_pdo, $doctor_id, $date);
    $slots = dcmt_generate_available_slots($duty_ranges, $busy_slots, $duration);

    echo json_encode(['success' => true, 'slots' => $slots]);
} catch (PDOException $e) {
    error_log('Available slots error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
?>
