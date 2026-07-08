<?php
require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';


$m = dcmt_appointment_messages();
$current_user = dcmt_get_current_user();
$role = $current_user['dcmt_role'] ?? '';

if (!(dcmt_is_admin() || in_array($role, ['staff', 'assistant'], true))) {
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
        $current_stmt = $dcmt_pdo->prepare("SELECT dcmt_status FROM dcmt_appointments WHERE dcmt_id = ? LIMIT 1");
        $current_stmt->execute([$appointment_id]);
        $current_appointment = $current_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current_appointment) {
            echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
            exit();
        }
        $current_status = dcmt_normalize_appointment_status((string)($current_appointment['dcmt_status'] ?? ''));
        if ($current_status === 'cancelled') {
            echo json_encode(['success' => false, 'message' => $m['cancelled_locked']]);
            exit();
        }
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
$confirm_outside_hours = (int)($_POST['confirm_outside_hours'] ?? 0) === 1;
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
    $clinic_ranges = dcmt_get_clinic_working_ranges($dcmt_pdo, $appointment_date);
    $effective_ranges = dcmt_intersect_time_ranges($duty_ranges, $clinic_ranges);
    $in_official_hours = !empty($effective_ranges)
        && dcmt_is_time_in_duty_ranges($start_dt, $end_dt, $effective_ranges);

    if (!$in_official_hours) {
        if (!$confirm_outside_hours) {
            echo json_encode([
                'success' => false,
                'needs_outside_hours_confirm' => true,
                'message' => trans('appointment', 'outside_hours_confirm_prompt'),
            ]);
            exit();
        }
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
        $status = dcmt_normalize_appointment_status($status);
        if ($status === 'cancelled') {
            echo json_encode(['success' => false, 'message' => $m['cancelled_locked']]);
            exit();
        }
        $times_changed = (
            (string)$existing_appointment['dcmt_start_at'] !== $start_at ||
            (string)$existing_appointment['dcmt_end_at'] !== $end_at
        );
        if ($status === 'completed' && $times_changed) {
            echo json_encode(['success' => false, 'message' => $m['completed_locked']]);
            exit();
        }
        $is_only_status_meta_update = !$times_changed;
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
