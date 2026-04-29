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

$user = dcmt_get_current_user();
if (!(dcmt_is_admin() || in_array($user['dcmt_role'] ?? '', ['staff', 'assistant'], true))) {
    echo json_encode(['success' => false, 'message' => $m['unauthorized']]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

$doctor_id = (int)($_POST['doctor_id'] ?? 0);
$rows = $_POST['duty'] ?? [];
$clinic_rows = is_array($_POST['clinic'] ?? null) ? $_POST['clinic'] : [];

if ($doctor_id <= 0 || !is_array($rows)) {
    echo json_encode(['success' => false, 'message' => $m['required_fields']]);
    exit();
}

try {
    if (!dcmt_is_doctor_user($dcmt_pdo, $doctor_id)) {
        echo json_encode(['success' => false, 'message' => $m['doctor_invalid']]);
        exit();
    }

    foreach ($rows as $weekday => $entry) {
        $weekday_int = (int)$weekday;
        if ($weekday_int < 0 || $weekday_int > 6) {
            continue;
        }
        $start = trim((string)($entry['start'] ?? '09:00'));
        $end = trim((string)($entry['end'] ?? '17:00'));
        if (strtotime($start) >= strtotime($end)) {
            echo json_encode(['success' => false, 'message' => $m['start_before_end']]);
            exit();
        }
    }

    for ($weekday_int = 0; $weekday_int <= 6; $weekday_int++) {
        $entry = $clinic_rows[$weekday_int] ?? [];
        $start = trim((string)($entry['start'] ?? '09:00'));
        $end = trim((string)($entry['end'] ?? '17:00'));
        if (strtotime($start) >= strtotime($end)) {
            echo json_encode(['success' => false, 'message' => $m['start_before_end']]);
            exit();
        }
    }

    $duty_clinic_err = dcmt_duty_post_must_fit_clinic_post($rows, $clinic_rows);
    if ($duty_clinic_err !== null) {
        $msg_key = $duty_clinic_err;
        $msg = $m[$msg_key] ?? trans('appointment', $msg_key);
        echo json_encode(['success' => false, 'message' => $msg]);
        exit();
    }

    $dcmt_pdo->beginTransaction();

    $del = $dcmt_pdo->prepare("DELETE FROM dcmt_doctor_duty_hours WHERE dcmt_doctor_id = ?");
    $del->execute([$doctor_id]);

    $ins = $dcmt_pdo->prepare("
        INSERT INTO dcmt_doctor_duty_hours
        (dcmt_doctor_id, dcmt_weekday, dcmt_start_time, dcmt_end_time, dcmt_is_active, dcmt_created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($rows as $weekday => $entry) {
        $weekday_int = (int)$weekday;
        if ($weekday_int < 0 || $weekday_int > 6) {
            continue;
        }
        $start = trim((string)($entry['start'] ?? '09:00'));
        $end = trim((string)($entry['end'] ?? '17:00'));
        $is_active = isset($entry['active']) ? 1 : 0;
        $ins->execute([$doctor_id, $weekday_int, $start . ':00', $end . ':00', $is_active, $user['dcmt_username']]);
    }

    $setting_ins = $dcmt_pdo->prepare("
        INSERT INTO dcmt_settings
        (dcmt_setting_key, dcmt_setting_name, dcmt_setting_value, dcmt_setting_type, dcmt_category, dcmt_created_by)
        VALUES (?, ?, ?, 'text', 'Appointment', ?)
        ON DUPLICATE KEY UPDATE
            dcmt_setting_value = VALUES(dcmt_setting_value),
            dcmt_updated_at = CURRENT_TIMESTAMP
    ");

    for ($weekday_int = 0; $weekday_int <= 6; $weekday_int++) {
        $entry = $clinic_rows[$weekday_int] ?? [];
        $start = trim((string)($entry['start'] ?? '09:00'));
        $end = trim((string)($entry['end'] ?? '17:00'));
        $is_active = isset($entry['active']) ? '1' : '0';

        $setting_ins->execute([
            "clinic_working_hours_{$weekday_int}_start",
            "Clinic Working Hours Day {$weekday_int} Start",
            $start,
            $user['dcmt_username']
        ]);
        $setting_ins->execute([
            "clinic_working_hours_{$weekday_int}_end",
            "Clinic Working Hours Day {$weekday_int} End",
            $end,
            $user['dcmt_username']
        ]);
        $setting_ins->execute([
            "clinic_working_hours_{$weekday_int}_active",
            "Clinic Working Hours Day {$weekday_int} Active",
            $is_active,
            $user['dcmt_username']
        ]);
    }

    $dcmt_pdo->commit();

    echo json_encode(['success' => true, 'message' => trans('appointment', 'duty_saved_success')]);
} catch (PDOException $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    error_log('Duty hours save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
