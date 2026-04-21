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
$current_user = dcmt_get_current_user();
$role = $current_user['dcmt_role'] ?? '';

if (!in_array($role, ['admin', 'staff', 'assistant'], true)) {
    echo json_encode(['success' => false, 'message' => $m['unauthorized']]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!dcmt_verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

$action = trim((string)($_POST['action'] ?? 'create'));
$appointment_id = (int)($_POST['appointment_id'] ?? 0);

if ($action === 'cancel') {
    if ($appointment_id <= 0) {
        echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
        exit();
    }
    try {
        $stmt = $dcmt_pdo->prepare("UPDATE dcmt_appointments SET dcmt_status = 'cancelled' WHERE dcmt_id = ?");
        $stmt->execute([$appointment_id]);
        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => $m['save_failed']]);
            exit();
        }
        echo json_encode(['success' => true, 'message' => $m['cancel_success']]);
    } catch (PDOException $e) {
        error_log('Appointment cancel error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $m['database_error']]);
    }
    exit();
}

$doctor_id = (int)($_POST['doctor_id'] ?? 0);
$operatory_id = (int)($_POST['operatory_id'] ?? 0);
$patient_id = (int)($_POST['patient_id'] ?? 0);
$appointment_date = trim((string)($_POST['appointment_date'] ?? ''));
$start_time = trim((string)($_POST['start_time'] ?? ''));
$end_time = trim((string)($_POST['end_time'] ?? ''));
$actual_start_time = trim((string)($_POST['actual_start_time'] ?? ''));
$actual_end_time = trim((string)($_POST['actual_end_time'] ?? ''));
$status = 'scheduled';
$reason = trim((string)($_POST['reason'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($doctor_id <= 0) {
    echo json_encode(['success' => false, 'field' => 'doctor_id', 'message' => $m['doctor_required']]);
    exit();
}
if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'field' => 'patient_id', 'message' => $m['patient_required']]);
    exit();
}
if ($operatory_id <= 0) {
    echo json_encode(['success' => false, 'field' => 'operatory_id', 'message' => $m['operatory_required']]);
    exit();
}
if ($appointment_date === '') {
    echo json_encode(['success' => false, 'field' => 'appointment_date', 'message' => $m['date_required']]);
    exit();
}
if ($start_time === '') {
    echo json_encode(['success' => false, 'field' => 'start_time', 'message' => $m['start_required']]);
    exit();
}
if ($end_time === '') {
    echo json_encode(['success' => false, 'field' => 'end_time', 'message' => $m['end_required']]);
    exit();
}
$start_dt = dcmt_datetime_from_parts($appointment_date, $start_time);
$end_dt = dcmt_datetime_from_parts($appointment_date, $end_time);
if (!$start_dt || !$end_dt) {
    echo json_encode(['success' => false, 'fields' => ['appointment_date', 'start_time', 'end_time'], 'message' => $m['invalid_datetime']]);
    exit();
}
if ($start_dt >= $end_dt) {
    echo json_encode(['success' => false, 'fields' => ['start_time', 'end_time'], 'message' => $m['start_before_end']]);
    exit();
}
$start_at = $start_dt->format('Y-m-d H:i:s');
$end_at = $end_dt->format('Y-m-d H:i:s');
$actual_start_at = null;
$actual_end_at = null;

if (($actual_start_time === '') xor ($actual_end_time === '')) {
    echo json_encode(['success' => false, 'fields' => ['actual_start_time', 'actual_end_time'], 'message' => $m['required_fields']]);
    exit();
}

if ($actual_start_time !== '' && $actual_end_time !== '') {
    $actual_start_dt = dcmt_datetime_from_parts($appointment_date, $actual_start_time);
    $actual_end_dt = dcmt_datetime_from_parts($appointment_date, $actual_end_time);
    if (!$actual_start_dt || !$actual_end_dt) {
        echo json_encode(['success' => false, 'fields' => ['actual_start_time', 'actual_end_time'], 'message' => $m['invalid_datetime']]);
        exit();
    }
    if ($actual_start_dt >= $actual_end_dt) {
        echo json_encode(['success' => false, 'fields' => ['actual_start_time', 'actual_end_time'], 'message' => $m['start_before_end']]);
        exit();
    }
    $actual_start_at = $actual_start_dt->format('Y-m-d H:i:s');
    $actual_end_at = $actual_end_dt->format('Y-m-d H:i:s');
}

try {
    if (!dcmt_is_doctor_user($dcmt_pdo, $doctor_id)) {
        echo json_encode(['success' => false, 'field' => 'doctor_id', 'message' => $m['doctor_invalid']]);
        exit();
    }
    if (!dcmt_patient_exists($dcmt_pdo, $patient_id)) {
        echo json_encode(['success' => false, 'field' => 'patient_id', 'message' => $m['patient_not_found']]);
        exit();
    }
    if (!dcmt_is_operatory_active($dcmt_pdo, $operatory_id)) {
        echo json_encode(['success' => false, 'field' => 'operatory_id', 'message' => $m['operatory_invalid']]);
        exit();
    }

    $duty_ranges = dcmt_get_doctor_duty_ranges($dcmt_pdo, $doctor_id, $appointment_date);
    if (empty($duty_ranges)) {
        echo json_encode(['success' => false, 'fields' => ['doctor_id', 'appointment_date'], 'message' => $m['doctor_unavailable_day']]);
        exit();
    }

    if (!dcmt_is_time_in_duty_ranges($start_dt, $end_dt, $duty_ranges)) {
        echo json_encode(['success' => false, 'fields' => ['start_time', 'end_time'], 'message' => $m['outside_duty_hours']]);
        exit();
    }

    $exclude_id = $action === 'update' ? $appointment_id : null;
    if (dcmt_has_operatory_overlap($dcmt_pdo, $operatory_id, $start_at, $end_at, $exclude_id)) {
        echo json_encode(['success' => false, 'fields' => ['start_time', 'end_time', 'operatory_id'], 'message' => $m['slot_booked']]);
        exit();
    }

    if ($action === 'update') {
        if ($appointment_id <= 0) {
            echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
            exit();
        }
        $existing_stmt = $dcmt_pdo->prepare("SELECT dcmt_start_at, dcmt_end_at, dcmt_status FROM dcmt_appointments WHERE dcmt_id = ?");
        $existing_stmt->execute([$appointment_id]);
        $existing_appointment = $existing_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing_appointment) {
            echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
            exit();
        }
        $status = (string)($existing_appointment['dcmt_status'] ?? 'scheduled');
        if ($status === '') {
            $status = 'scheduled';
        }
        $is_only_status_meta_update = (
            (string)$existing_appointment['dcmt_start_at'] === $start_at &&
            (string)$existing_appointment['dcmt_end_at'] === $end_at
        );
        if ($start_dt < new DateTime() && !$is_only_status_meta_update) {
            echo json_encode(['success' => false, 'fields' => ['appointment_date', 'start_time'], 'message' => $m['past_booking']]);
            exit();
        }
        $stmt = $dcmt_pdo->prepare("
            UPDATE dcmt_appointments
            SET dcmt_patient_id = ?, dcmt_doctor_id = ?, dcmt_operatory_id = ?, dcmt_start_at = ?, dcmt_end_at = ?,
                dcmt_actual_start_at = ?, dcmt_actual_end_at = ?, dcmt_status = ?, dcmt_reason = ?, dcmt_notes = ?
            WHERE dcmt_id = ?
        ");
        $stmt->execute([$patient_id, $doctor_id, $operatory_id, $start_at, $end_at, $actual_start_at, $actual_end_at, $status, $reason !== '' ? $reason : null, $notes !== '' ? $notes : null, $appointment_id]);
        echo json_encode(['success' => true, 'message' => $m['update_success']]);
        exit();
    }

    if ($start_dt < new DateTime()) {
        echo json_encode(['success' => false, 'fields' => ['appointment_date', 'start_time'], 'message' => $m['past_booking']]);
        exit();
    }

    $stmt = $dcmt_pdo->prepare("
        INSERT INTO dcmt_appointments
        (dcmt_patient_id, dcmt_doctor_id, dcmt_operatory_id, dcmt_start_at, dcmt_end_at, dcmt_actual_start_at, dcmt_actual_end_at, dcmt_status, dcmt_reason, dcmt_notes, dcmt_created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $patient_id,
        $doctor_id,
        $operatory_id,
        $start_at,
        $end_at,
        $actual_start_at,
        $actual_end_at,
        $status,
        $reason !== '' ? $reason : null,
        $notes !== '' ? $notes : null,
        (int)$current_user['dcmt_id']
    ]);
    echo json_encode(['success' => true, 'message' => $m['create_success']]);
} catch (PDOException $e) {
    error_log('Appointment save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
?>
