<?php
/**
 * Appointment helper functions
 * Dental Clinic Management System
 */

function dcmt_appointment_messages()
{
    return [
        'required_fields' => trans('appointment', 'required_fields'),
        'invalid_request' => trans('appointment', 'invalid_request'),
        'unauthorized' => trans('appointment', 'unauthorized'),
        'doctor_required' => trans('appointment', 'doctor_required'),
        'doctor_invalid' => trans('appointment', 'doctor_invalid'),
        'doctor_unavailable_day' => trans('appointment', 'doctor_unavailable_day'),
        'outside_duty_hours' => trans('appointment', 'outside_duty_hours'),
        'patient_required' => trans('appointment', 'patient_required'),
        'patient_invalid' => trans('appointment', 'patient_invalid'),
        'patient_not_found' => trans('appointment', 'patient_not_found'),
        'date_required' => trans('appointment', 'date_required'),
        'start_required' => trans('appointment', 'start_required'),
        'end_required' => trans('appointment', 'end_required'),
        'start_before_end' => trans('appointment', 'start_before_end'),
        'invalid_datetime' => trans('appointment', 'invalid_datetime'),
        'past_booking' => trans('appointment', 'past_booking'),
        'slot_booked' => trans('appointment', 'slot_booked'),
        'slot_changed' => trans('appointment', 'slot_changed'),
        'status_invalid' => trans('appointment', 'status_invalid'),
        'create_success' => trans('appointment', 'create_success'),
        'update_success' => trans('appointment', 'update_success'),
        'cancel_success' => trans('appointment', 'cancel_success'),
        'save_failed' => trans('appointment', 'save_failed'),
        'system_error' => trans('appointment', 'system_error'),
        'database_error' => trans('appointment', 'database_error'),
    ];
}

function dcmt_is_staff_or_admin()
{
    $user = dcmt_get_current_user();
    if (!$user) {
        return false;
    }
    return in_array($user['dcmt_role'] ?? '', ['staff', 'admin'], true);
}

function dcmt_is_doctor_user(PDO $pdo, $doctor_id)
{
    $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_users WHERE dcmt_id = ? AND dcmt_role = 'doctor' AND dcmt_status = 'active' LIMIT 1");
    $stmt->execute([(int)$doctor_id]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function dcmt_patient_exists(PDO $pdo, $patient_id)
{
    $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_patients WHERE dcmt_id = ? LIMIT 1");
    $stmt->execute([(int)$patient_id]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function dcmt_get_doctor_duty_ranges(PDO $pdo, $doctor_id, $date_ymd)
{
    $weekday = (int)date('w', strtotime($date_ymd));
    $stmt = $pdo->prepare("
        SELECT dcmt_start_time, dcmt_end_time
        FROM dcmt_doctor_duty_hours
        WHERE dcmt_doctor_id = ?
          AND dcmt_weekday = ?
          AND dcmt_is_active = 1
        ORDER BY dcmt_start_time ASC
    ");
    $stmt->execute([(int)$doctor_id, $weekday]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dcmt_datetime_from_parts($date, $time)
{
    $dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if (!$dt) {
        return null;
    }
    return $dt;
}

function dcmt_is_time_in_duty_ranges(DateTime $start, DateTime $end, array $ranges)
{
    $start_minutes = ((int)$start->format('H') * 60) + (int)$start->format('i');
    $end_minutes = ((int)$end->format('H') * 60) + (int)$end->format('i');

    foreach ($ranges as $range) {
        $duty_start = DateTime::createFromFormat('H:i:s', $range['dcmt_start_time']);
        $duty_end = DateTime::createFromFormat('H:i:s', $range['dcmt_end_time']);
        if (!$duty_start || !$duty_end) {
            continue;
        }
        $duty_start_minutes = ((int)$duty_start->format('H') * 60) + (int)$duty_start->format('i');
        $duty_end_minutes = ((int)$duty_end->format('H') * 60) + (int)$duty_end->format('i');
        if ($start_minutes >= $duty_start_minutes && $end_minutes <= $duty_end_minutes) {
            return true;
        }
    }
    return false;
}

function dcmt_has_appointment_overlap(PDO $pdo, $doctor_id, $start_at, $end_at, $exclude_id = null)
{
    $sql = "
        SELECT dcmt_id
        FROM dcmt_appointments
        WHERE dcmt_doctor_id = ?
          AND dcmt_status <> 'cancelled'
          AND (? < dcmt_end_at AND ? > dcmt_start_at)
    ";
    $params = [(int)$doctor_id, $start_at, $end_at];
    if ($exclude_id !== null) {
        $sql .= " AND dcmt_id <> ?";
        $params[] = (int)$exclude_id;
    }
    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function dcmt_get_busy_slots(PDO $pdo, $doctor_id, $date_ymd)
{
    $day_start = $date_ymd . ' 00:00:00';
    $day_end = $date_ymd . ' 23:59:59';
    $stmt = $pdo->prepare("
        SELECT dcmt_start_at, dcmt_end_at
        FROM dcmt_appointments
        WHERE dcmt_doctor_id = ?
          AND dcmt_status <> 'cancelled'
          AND dcmt_start_at <= ?
          AND dcmt_end_at >= ?
    ");
    $stmt->execute([(int)$doctor_id, $day_end, $day_start]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dcmt_generate_available_slots(array $duty_ranges, array $busy_slots, $slot_minutes = 30)
{
    $slots = [];
    foreach ($duty_ranges as $range) {
        $start = DateTime::createFromFormat('H:i:s', $range['dcmt_start_time']);
        $end = DateTime::createFromFormat('H:i:s', $range['dcmt_end_time']);
        if (!$start || !$end) {
            continue;
        }

        $cursor = clone $start;
        while ($cursor < $end) {
            $slot_start = clone $cursor;
            $slot_end = (clone $slot_start)->modify('+' . (int)$slot_minutes . ' minutes');
            if ($slot_end > $end) {
                break;
            }

            $overlap = false;
            foreach ($busy_slots as $busy) {
                $busy_start = DateTime::createFromFormat('Y-m-d H:i:s', $busy['dcmt_start_at']);
                $busy_end = DateTime::createFromFormat('Y-m-d H:i:s', $busy['dcmt_end_at']);
                if (!$busy_start || !$busy_end) {
                    continue;
                }
                if ($slot_start < $busy_end && $slot_end > $busy_start) {
                    $overlap = true;
                    break;
                }
            }

            if (!$overlap) {
                $slots[] = [
                    'start' => $slot_start->format('H:i'),
                    'end' => $slot_end->format('H:i'),
                ];
            }
            $cursor->modify('+' . (int)$slot_minutes . ' minutes');
        }
    }

    return $slots;
}

function dcmt_normalize_appointment_status($status)
{
    $status = trim((string)$status);
    if ($status === 'confirmed') {
        return 'scheduled';
    }
    if ($status === 'no_show') {
        return 'cancelled';
    }
    if (!in_array($status, ['scheduled', 'completed', 'cancelled'], true)) {
        return 'scheduled';
    }
    return $status;
}
?>
