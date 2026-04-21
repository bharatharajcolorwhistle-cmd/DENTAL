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
$operatory_id = (int)($_GET['operatory_id'] ?? 0);
$date = trim((string)($_GET['date'] ?? ''));
$duration = (int)($_GET['duration'] ?? 30);
if ($duration < 5) {
    $duration = 30;
}

if ($doctor_id <= 0) {
    echo json_encode(['success' => false, 'message' => $m['doctor_required']]);
    exit();
}
if ($operatory_id <= 0) {
    echo json_encode(['success' => false, 'message' => $m['operatory_required']]);
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
    if (!dcmt_is_operatory_active($dcmt_pdo, $operatory_id)) {
        echo json_encode(['success' => false, 'message' => $m['operatory_invalid']]);
        exit();
    }

    $duty_ranges = dcmt_get_doctor_duty_ranges($dcmt_pdo, $doctor_id, $date);
    if (empty($duty_ranges)) {
        echo json_encode(['success' => false, 'message' => $m['doctor_unavailable_day'], 'slots' => []]);
        exit();
    }

    $busy_slots = dcmt_get_busy_slots_for_operatory($dcmt_pdo, $operatory_id, $date);
    $slots = dcmt_generate_available_slots($duty_ranges, $busy_slots, $duration);

    $busy_list = [];
    foreach ($busy_slots as $b) {
        try {
            $bs = new DateTime($b['dcmt_start_at']);
            $be = new DateTime($b['dcmt_end_at']);
            $busy_list[] = [
                'start' => $bs->format('H:i'),
                'end' => $be->format('H:i'),
            ];
        } catch (Exception $e) {
            continue;
        }
    }
    usort($busy_list, static function ($a, $b) {
        return strcmp($a['start'], $b['start']);
    });

    echo json_encode(['success' => true, 'slots' => $slots, 'busy' => $busy_list]);
} catch (PDOException $e) {
    error_log('Available slots error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
?>
