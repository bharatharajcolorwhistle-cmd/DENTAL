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
        'operatory_required' => trans('appointment', 'operatory_required'),
        'operatory_invalid' => trans('appointment', 'operatory_invalid'),
        'operatories_saved' => trans('appointment', 'operatories_saved'),
        'operatories_save_failed' => trans('appointment', 'operatories_save_failed'),
        'operatory_name_required' => trans('appointment', 'operatory_name_required'),
        'operatory_delete_blocked' => trans('appointment', 'operatory_delete_blocked'),
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

/**
 * True if another non-cancelled appointment uses the same operatory in an overlapping interval.
 * Doctor may have parallel appointments on different operatories.
 */
function dcmt_has_operatory_overlap(PDO $pdo, $operatory_id, $start_at, $end_at, $exclude_id = null)
{
    $sql = "
        SELECT dcmt_id
        FROM dcmt_appointments
        WHERE dcmt_operatory_id = ?
          AND dcmt_status <> 'cancelled'
          AND (? < COALESCE(dcmt_actual_end_at, dcmt_end_at) AND ? > COALESCE(dcmt_actual_start_at, dcmt_start_at))
    ";
    $params = [(int)$operatory_id, $start_at, $end_at];
    if ($exclude_id !== null) {
        $sql .= " AND dcmt_id <> ?";
        $params[] = (int)$exclude_id;
    }
    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function dcmt_is_operatory_active(PDO $pdo, $operatory_id)
{
    $stmt = $pdo->prepare("
        SELECT dcmt_id FROM dcmt_operatories
        WHERE dcmt_id = ? AND dcmt_is_active = 1
        LIMIT 1
    ");
    $stmt->execute([(int)$operatory_id]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Persist clinic-wide operatories. Caller manages transactions.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array{ok: true}|array{ok: false, message: string}
 */
function dcmt_save_operatories_global(PDO $pdo, array $rows, array $m)
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => $m['operatory_name_required']];
        }
        if (strlen($name) > 120) {
            return ['ok' => false, 'message' => $m['invalid_request']];
        }
    }

    $incoming_ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $incoming_ids[] = $id;
        }
    }

    $selIds = $pdo->query("SELECT dcmt_id FROM dcmt_operatories");
    $db_ids = array_map('intval', $selIds->fetchAll(PDO::FETCH_COLUMN));
    $to_remove = array_diff($db_ids, $incoming_ids);

    foreach ($to_remove as $rid) {
        $cnt = $pdo->prepare("
            SELECT COUNT(*) FROM dcmt_appointments
            WHERE dcmt_operatory_id = ? AND dcmt_status <> 'cancelled'
        ");
        $cnt->execute([$rid]);
        if ((int)$cnt->fetchColumn() > 0) {
            return ['ok' => false, 'message' => $m['operatory_delete_blocked']];
        }
        $del = $pdo->prepare("DELETE FROM dcmt_operatories WHERE dcmt_id = ?");
        $del->execute([$rid]);
    }

    $upd = $pdo->prepare("
        UPDATE dcmt_operatories
        SET dcmt_name = ?, dcmt_sort_order = ?, dcmt_is_active = ?
        WHERE dcmt_id = ?
    ");
    $ins = $pdo->prepare("
        INSERT INTO dcmt_operatories (dcmt_name, dcmt_sort_order, dcmt_is_active)
        VALUES (?, ?, ?)
    ");

    $sort = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $sort++;
        $is_active = isset($row['is_active']) && (int)$row['is_active'] === 1 ? 1 : 0;
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $upd->execute([$name, $sort, $is_active, $id]);
        } else {
            $ins->execute([$name, $sort, $is_active]);
        }
    }

    return ['ok' => true];
}

function dcmt_get_busy_slots_for_operatory(PDO $pdo, $operatory_id, $date_ymd)
{
    $day_start = $date_ymd . ' 00:00:00';
    $day_end = $date_ymd . ' 23:59:59';
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(dcmt_actual_start_at, dcmt_start_at) AS dcmt_start_at,
            COALESCE(dcmt_actual_end_at, dcmt_end_at) AS dcmt_end_at
        FROM dcmt_appointments
        WHERE dcmt_operatory_id = ?
          AND dcmt_status <> 'cancelled'
          AND COALESCE(dcmt_actual_start_at, dcmt_start_at) <= ?
          AND COALESCE(dcmt_actual_end_at, dcmt_end_at) >= ?
    ");
    $stmt->execute([(int)$operatory_id, $day_end, $day_start]);
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
