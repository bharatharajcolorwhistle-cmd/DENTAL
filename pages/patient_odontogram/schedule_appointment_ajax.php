<?php
/**
 * Schedule an appointment from a treatment plan line.
 * Creates the appointment and links it back to the plan line.
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';
require_once __DIR__ . '/../../includes/patient_treatment_plan.php';

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

if (!dcmt_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

$patient_id = (int) ($_POST['patient_id'] ?? 0);
$doctor_id = (int) ($_POST['doctor_id'] ?? 0);
$operatory_id = (int) ($_POST['operatory_id'] ?? 0);
$appointment_date = trim((string) ($_POST['appointment_date'] ?? ''));
$start_time = trim((string) ($_POST['start_time'] ?? ''));
$duration = (int) ($_POST['duration'] ?? 30);
$reason = trim((string) ($_POST['reason'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$plan_line_id = trim((string) ($_POST['plan_line_id'] ?? ''));
$plan_group_id = trim((string) ($_POST['plan_group_id'] ?? ''));
$plan_lines_json = (string) ($_POST['plan_lines_json'] ?? '');
$confirm_outside_hours = (int) ($_POST['confirm_outside_hours'] ?? 0) === 1;

if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'field' => 'patient_id', 'message' => $m['patient_required']]);
    exit();
}
if ($doctor_id <= 0) {
    echo json_encode(['success' => false, 'field' => 'doctor_id', 'message' => $m['doctor_required']]);
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
if ($duration < 5) {
    $duration = 30;
}
if ($duration > 480) {
    $duration = 480;
}

$start_dt = dcmt_datetime_from_parts($appointment_date, $start_time);
if (!$start_dt) {
    echo json_encode(['success' => false, 'fields' => ['appointment_date', 'start_time'], 'message' => $m['invalid_datetime']]);
    exit();
}
if ($start_dt < new DateTime()) {
    echo json_encode(['success' => false, 'fields' => ['appointment_date', 'start_time'], 'message' => $m['past_booking']]);
    exit();
}

$end_dt = (clone $start_dt)->modify('+' . $duration . ' minutes');
$start_at = $start_dt->format('Y-m-d H:i:s');
$end_at = $end_dt->format('Y-m-d H:i:s');

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

    // Fall back to the plan line description when the user leaves the reason blank.
    if ($reason === '' && $plan_line_id !== '') {
        $saved_plan = dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id);
        if ($saved_plan !== null) {
            $plan_line = dcmt_treatment_plan_find_line($saved_plan['lines'] ?? [], $plan_line_id);
            if ($plan_line !== null) {
                $reason = dcmt_treatment_plan_line_reason($plan_line);
            }
        }
    }
    // Fall back to the group description when scheduling a confirmed visit group.
    if ($reason === '' && $plan_group_id !== '') {
        $saved_plan = $saved_plan ?? dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id);
        if ($saved_plan !== null) {
            foreach (dcmt_treatment_plan_group_lines($saved_plan['lines'] ?? []) as $plan_group) {
                if ((string) ($plan_group['id'] ?? '') === $plan_group_id) {
                    $reason = dcmt_treatment_plan_group_reason($plan_group);
                    break;
                }
            }
        }
    }
    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 255);
    } else {
        $reason = substr($reason, 0, 255);
    }

    $duty_ranges = dcmt_get_doctor_duty_ranges($dcmt_pdo, $doctor_id, $appointment_date);
    $clinic_ranges = dcmt_get_clinic_working_ranges($dcmt_pdo, $appointment_date);
    $effective_ranges = dcmt_intersect_time_ranges($duty_ranges, $clinic_ranges);
    $in_official_hours = !empty($effective_ranges)
        && dcmt_is_time_in_duty_ranges($start_dt, $end_dt, $effective_ranges);

    if (!$in_official_hours && !$confirm_outside_hours) {
        echo json_encode([
            'success' => false,
            'needs_outside_hours_confirm' => true,
            'message' => trans('appointment', 'outside_hours_confirm_prompt'),
        ]);
        exit();
    }

    if (dcmt_has_operatory_overlap($dcmt_pdo, $operatory_id, $start_at, $end_at)) {
        echo json_encode([
            'success' => false,
            'fields' => ['start_time', 'operatory_id'],
            'message' => $m['slot_booked'],
        ]);
        exit();
    }

    // Persist the current plan state so the scheduled line can be linked after reload.
    // NOTE: kept outside a DB transaction — the treatment plan helpers run DDL
    // (CREATE TABLE IF NOT EXISTS), which implicitly commits in MySQL.
    dcmt_ensure_patient_treatment_plans_table($dcmt_pdo);
    $posted_plan_lines = $plan_lines_json !== '' ? json_decode($plan_lines_json, true) : null;
    if (is_array($posted_plan_lines)) {
        $saved_plan_before = dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id);
        $plan_doctor_for_save = (int) ($saved_plan_before['dcmt_doctor_user_id'] ?? 0);
        if ($plan_doctor_for_save <= 0) {
            $plan_doctor_for_save = $doctor_id;
        }
        $odontogram_json = dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id);
        $odontogram_document = dcmt_patient_odontogram_decode_document($odontogram_json);
        $plan_summary = dcmt_patient_odontogram_solution_summary($odontogram_document);
        // Default doctor 0 keeps any per-line doctor the user has not chosen unset.
        $normalized_plan_lines = dcmt_normalize_treatment_plan_lines($posted_plan_lines, $dcmt_pdo, 0);
        dcmt_save_patient_treatment_plan(
            $dcmt_pdo,
            $patient_id,
            $plan_doctor_for_save,
            $plan_summary,
            $normalized_plan_lines,
            (string) ($saved_plan_before['dcmt_notes'] ?? ''),
            $current_user['dcmt_username'] ?? null
        );
    }

    $stmt = $dcmt_pdo->prepare("
        INSERT INTO dcmt_appointments
        (dcmt_patient_id, dcmt_doctor_id, dcmt_operatory_id, dcmt_start_at, dcmt_end_at, dcmt_actual_start_at, dcmt_actual_end_at, dcmt_status, dcmt_reason, dcmt_notes, dcmt_created_by)
        VALUES (?, ?, ?, ?, ?, NULL, NULL, 'scheduled', ?, ?, ?)
    ");
    $stmt->execute([
        $patient_id,
        $doctor_id,
        $operatory_id,
        $start_at,
        $end_at,
        $reason !== '' ? $reason : null,
        $notes !== '' ? $notes : null,
        (int) ($current_user['dcmt_id'] ?? 0),
    ]);
    $appointment_id = (int) $dcmt_pdo->lastInsertId();

    dcmt_appointment_audit_log($dcmt_pdo, $appointment_id, 'created', [
        'actor_id' => (int)($current_user['dcmt_id'] ?? 0),
        'actor_name' => (string)($current_user['dcmt_full_name'] ?? ''),
        'actor_role' => (string)($current_user['dcmt_role'] ?? ''),
        'source' => 'treatment_plan',
        'new_status' => 'scheduled',
        'new_value' => $start_at,
        'details' => $plan_group_id !== ''
            ? 'Scheduled from treatment plan group ' . $plan_group_id
            : ($plan_line_id !== '' ? 'Scheduled from treatment plan line ' . $plan_line_id : 'Scheduled from treatment plan'),
    ]);

    // Best-effort link; the appointment already exists, so a link failure must not fail the request.
    $linked_line_ids = [];
    if ($plan_group_id !== '') {
        $linked_line_ids = dcmt_treatment_plan_mark_group_scheduled(
            $dcmt_pdo,
            $patient_id,
            $plan_group_id,
            $appointment_id,
            $start_at
        );
    } elseif ($plan_line_id !== '') {
        dcmt_treatment_plan_mark_line_scheduled($dcmt_pdo, $patient_id, $plan_line_id, $appointment_id, $start_at);
        $linked_line_ids = [$plan_line_id];
    }

    dcmt_log_activity(
        'Appointment scheduled from treatment plan',
        "Appointment ID: {$appointment_id}, Patient ID: {$patient_id}, Plan line: {$plan_line_id}, Group: {$plan_group_id}"
    );

    echo json_encode([
        'success' => true,
        'message' => $m['create_success'],
        'appointment_id' => $appointment_id,
        'appointment_view_url' => 'appointments/view.php?id=' . $appointment_id,
        'scheduled_start_at' => $start_at,
        'scheduled_start_display' => date('M j, Y g:i A', strtotime($start_at)),
        'plan_line_id' => $plan_line_id,
        'plan_group_id' => $plan_group_id,
        'linked_line_ids' => $linked_line_ids,
    ]);
} catch (PDOException $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    error_log('Treatment plan appointment schedule error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
