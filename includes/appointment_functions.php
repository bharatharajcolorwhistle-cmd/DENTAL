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
        'cancelled_locked' => trans('appointment', 'cancelled_locked'),
        'completed_locked' => trans('appointment', 'completed_locked'),
        'create_success' => trans('appointment', 'create_success'),
        'update_success' => trans('appointment', 'update_success'),
        'cancel_success' => trans('appointment', 'cancel_success'),
        'save_failed' => trans('appointment', 'save_failed'),
        'system_error' => trans('appointment', 'system_error'),
        'database_error' => trans('appointment', 'database_error'),
        'duty_exceeds_clinic' => trans('appointment', 'duty_exceeds_clinic'),
        'duty_on_closed_clinic_day' => trans('appointment', 'duty_on_closed_clinic_day'),
    ];
}

/**
 * WhatsApp deep links for a patient phone (reminder message + voice call).
 *
 * @return array{message: string, call: string, has_phone: bool}
 */
function dcmt_appointment_whatsapp_links(string $phone, string $reminder_text = ''): array
{
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return ['message' => '#', 'call' => '#', 'has_phone' => false];
    }

    $message_url = 'https://wa.me/' . $digits;
    if ($reminder_text !== '') {
        $message_url .= '?text=' . rawurlencode($reminder_text);
    }

    return [
        'message' => $message_url,
        'call' => 'https://wa.me/call/' . $digits,
        'has_phone' => true,
    ];
}

function dcmt_is_staff_or_admin()
{
    $user = dcmt_get_current_user();
    if (!$user) {
        return false;
    }
    return dcmt_is_admin() || ($user['dcmt_role'] ?? '') === 'staff';
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

function dcmt_get_clinic_working_ranges(PDO $pdo, $date_ymd)
{
    $weekday = (int)date('w', strtotime($date_ymd));
    $start_key = "clinic_working_hours_{$weekday}_start";
    $end_key = "clinic_working_hours_{$weekday}_end";
    $active_key = "clinic_working_hours_{$weekday}_active";

    $stmt = $pdo->prepare("
        SELECT dcmt_setting_key, dcmt_setting_value
        FROM dcmt_settings
        WHERE dcmt_setting_key IN (?, ?, ?)
    ");
    $stmt->execute([$start_key, $end_key, $active_key]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $start = '09:00:00';
    $end = '17:00:00';
    $active = 1;
    foreach ($rows as $row) {
        $key = (string)($row['dcmt_setting_key'] ?? '');
        $value = trim((string)($row['dcmt_setting_value'] ?? ''));
        if ($key === $start_key) {
            $start = strlen($value) === 5 ? ($value . ':00') : $value;
        } elseif ($key === $end_key) {
            $end = strlen($value) === 5 ? ($value . ':00') : $value;
        } elseif ($key === $active_key) {
            $active = ($value === '1') ? 1 : 0;
        }
    }

    if ($active !== 1) {
        return [];
    }
    return [[
        'dcmt_start_time' => $start,
        'dcmt_end_time' => $end,
    ]];
}

function dcmt_datetime_from_parts($date, $time)
{
    $dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if (!$dt) {
        return null;
    }
    return $dt;
}

/**
 * Parse a time string (H:i or H:i:s) to minutes from midnight.
 */
function dcmt_time_string_to_minutes(string $value): ?int
{
    $v = trim($value);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m)) {
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
            return $h * 60 + $i;
        }
    }
    return null;
}

/**
 * Active doctor duty rows must fall within the same weekday's clinic window from the same save request.
 *
 * @param array<int|string, array<string, mixed>> $duty_rows
 * @param array<int|string, array<string, mixed>> $clinic_rows
 * @return string|null appointment translation key when invalid
 */
function dcmt_duty_post_must_fit_clinic_post(array $duty_rows, array $clinic_rows): ?string
{
    for ($w = 0; $w <= 6; $w++) {
        $de = $duty_rows[$w] ?? [];
        $ce = $clinic_rows[$w] ?? [];
        $duty_active = isset($de['active']) && ($de['active'] === '1' || $de['active'] === 1 || $de['active'] === true);
        if (!$duty_active) {
            continue;
        }
        $clinic_active = isset($ce['active']) && ($ce['active'] === '1' || $ce['active'] === 1 || $ce['active'] === true);
        if (!$clinic_active) {
            return 'duty_on_closed_clinic_day';
        }
        $ds = trim((string)($de['start'] ?? '09:00'));
        $den = trim((string)($de['end'] ?? '17:00'));
        $cs = trim((string)($ce['start'] ?? '09:00'));
        $cen = trim((string)($ce['end'] ?? '17:00'));
        $dS = dcmt_time_string_to_minutes($ds);
        $dE = dcmt_time_string_to_minutes($den);
        $cS = dcmt_time_string_to_minutes($cs);
        $cE = dcmt_time_string_to_minutes($cen);
        if ($dS === null || $dE === null || $cS === null || $cE === null) {
            return 'invalid_datetime';
        }
        if ($dS < $cS || $dE > $cE) {
            return 'duty_exceeds_clinic';
        }
    }
    return null;
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

function dcmt_intersect_time_ranges(array $ranges_a, array $ranges_b)
{
    $result = [];
    foreach ($ranges_a as $a) {
        $a_start = DateTime::createFromFormat('H:i:s', (string)($a['dcmt_start_time'] ?? ''));
        $a_end = DateTime::createFromFormat('H:i:s', (string)($a['dcmt_end_time'] ?? ''));
        if (!$a_start || !$a_end) {
            continue;
        }
        $a_start_minutes = ((int)$a_start->format('H') * 60) + (int)$a_start->format('i');
        $a_end_minutes = ((int)$a_end->format('H') * 60) + (int)$a_end->format('i');
        if ($a_start_minutes >= $a_end_minutes) {
            continue;
        }

        foreach ($ranges_b as $b) {
            $b_start = DateTime::createFromFormat('H:i:s', (string)($b['dcmt_start_time'] ?? ''));
            $b_end = DateTime::createFromFormat('H:i:s', (string)($b['dcmt_end_time'] ?? ''));
            if (!$b_start || !$b_end) {
                continue;
            }
            $b_start_minutes = ((int)$b_start->format('H') * 60) + (int)$b_start->format('i');
            $b_end_minutes = ((int)$b_end->format('H') * 60) + (int)$b_end->format('i');
            if ($b_start_minutes >= $b_end_minutes) {
                continue;
            }

            $start_minutes = max($a_start_minutes, $b_start_minutes);
            $end_minutes = min($a_end_minutes, $b_end_minutes);
            if ($start_minutes < $end_minutes) {
                $result[] = [
                    'dcmt_start_time' => sprintf('%02d:%02d:00', intdiv($start_minutes, 60), $start_minutes % 60),
                    'dcmt_end_time' => sprintf('%02d:%02d:00', intdiv($end_minutes, 60), $end_minutes % 60),
                ];
            }
        }
    }
    return $result;
}

/**
 * Merge overlapping duty-style time ranges (H:i:s) for the same weekday.
 *
 * @param array<int, array{dcmt_start_time: string, dcmt_end_time: string}> $ranges
 * @return array<int, array{dcmt_start_time: string, dcmt_end_time: string}>
 */
function dcmt_union_time_ranges(array $ranges)
{
    $intervals = [];
    foreach ($ranges as $range) {
        $a = DateTime::createFromFormat('H:i:s', (string)($range['dcmt_start_time'] ?? ''));
        $b = DateTime::createFromFormat('H:i:s', (string)($range['dcmt_end_time'] ?? ''));
        if (!$a || !$b) {
            continue;
        }
        $a_min = ((int)$a->format('H') * 60) + (int)$a->format('i');
        $b_min = ((int)$b->format('H') * 60) + (int)$b->format('i');
        if ($a_min >= $b_min) {
            continue;
        }
        $intervals[] = [$a_min, $b_min];
    }
    if (count($intervals) === 0) {
        return [];
    }
    usort($intervals, function ($x, $y) {
        return $x[0] <=> $y[0];
    });
    $merged = [];
    $cur = $intervals[0];
    for ($i = 1, $n = count($intervals); $i < $n; $i++) {
        if ($intervals[$i][0] <= $cur[1]) {
            $cur[1] = max($cur[1], $intervals[$i][1]);
        } else {
            $merged[] = $cur;
            $cur = $intervals[$i];
        }
    }
    $merged[] = $cur;
    $out = [];
    foreach ($merged as $m) {
        $out[] = [
            'dcmt_start_time' => sprintf('%02d:%02d:00', intdiv($m[0], 60), $m[0] % 60),
            'dcmt_end_time' => sprintf('%02d:%02d:00', intdiv($m[1], 60), $m[1] % 60),
        ];
    }
    return $out;
}

/**
 * Clinic opening hours for FullCalendar + slot bounds (widest active clinic day range).
 *
 * @return array{business_hours: array<int, array{daysOfWeek: int[], startTime: string, endTime: string}>, slot_min_time: string, slot_max_time: string}
 */
function dcmt_load_clinic_calendar_config(PDO $pdo)
{
    $calendar_business_hours = [];
    $calendar_slot_min_time = '09:00:00';
    $calendar_slot_max_time = '18:00:00';

    try {
        $clinic_stmt = $pdo->query("
            SELECT dcmt_setting_key, dcmt_setting_value
            FROM dcmt_settings
            WHERE dcmt_setting_key LIKE 'clinic_working_hours_%'
        ");
        $clinic_rows = $clinic_stmt ? $clinic_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        error_log('dcmt_load_clinic_calendar_config: ' . $e->getMessage());
        $clinic_rows = [];
    }

    $clinic_map = [];
    foreach ($clinic_rows as $setting_row) {
        $key = (string)($setting_row['dcmt_setting_key'] ?? '');
        $value = trim((string)($setting_row['dcmt_setting_value'] ?? ''));
        if (preg_match('/^clinic_working_hours_(\d+)_(start|end|active)$/', $key, $matches)) {
            $day = (int)$matches[1];
            if ($day < 0 || $day > 6) {
                continue;
            }
            if (!isset($clinic_map[$day])) {
                $clinic_map[$day] = ['start' => '09:00', 'end' => '17:00', 'active' => 1];
            }
            if ($matches[2] === 'start') {
                $clinic_map[$day]['start'] = $value;
            } elseif ($matches[2] === 'end') {
                $clinic_map[$day]['end'] = $value;
            } else {
                $clinic_map[$day]['active'] = ($value === '1') ? 1 : 0;
            }
        }
    }

    $min_minutes = null;
    $max_minutes = null;
    foreach ($clinic_map as $day_cfg) {
        if ((int)($day_cfg['active'] ?? 0) !== 1) {
            continue;
        }
        $start_hm = substr((string)($day_cfg['start'] ?? '09:00'), 0, 5);
        $end_hm = substr((string)($day_cfg['end'] ?? '17:00'), 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $start_hm) || !preg_match('/^\d{2}:\d{2}$/', $end_hm)) {
            continue;
        }
        $start_minutes = ((int)substr($start_hm, 0, 2) * 60) + (int)substr($start_hm, 3, 2);
        $end_minutes = ((int)substr($end_hm, 0, 2) * 60) + (int)substr($end_hm, 3, 2);
        if ($start_minutes >= $end_minutes) {
            continue;
        }
        if ($min_minutes === null || $start_minutes < $min_minutes) {
            $min_minutes = $start_minutes;
        }
        if ($max_minutes === null || $end_minutes > $max_minutes) {
            $max_minutes = $end_minutes;
        }
    }

    for ($day = 0; $day <= 6; $day++) {
        $day_cfg = $clinic_map[$day] ?? ['start' => '09:00', 'end' => '17:00', 'active' => 1];
        if ((int)($day_cfg['active'] ?? 0) !== 1) {
            continue;
        }
        $start_hm = substr((string)($day_cfg['start'] ?? '09:00'), 0, 5);
        $end_hm = substr((string)($day_cfg['end'] ?? '17:00'), 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $start_hm) || !preg_match('/^\d{2}:\d{2}$/', $end_hm)) {
            continue;
        }
        $start_minutes = ((int)substr($start_hm, 0, 2) * 60) + (int)substr($start_hm, 3, 2);
        $end_minutes = ((int)substr($end_hm, 0, 2) * 60) + (int)substr($end_hm, 3, 2);
        if ($start_minutes >= $end_minutes) {
            continue;
        }
        $calendar_business_hours[] = [
            'daysOfWeek' => [$day],
            'startTime' => $start_hm,
            'endTime' => $end_hm,
        ];
    }

    if ($min_minutes !== null && $max_minutes !== null && $min_minutes < $max_minutes) {
        $calendar_slot_min_time = sprintf('%02d:%02d:00', intdiv($min_minutes, 60), $min_minutes % 60);
        $display_max_minutes = min(24 * 60, $max_minutes + 30);
        $calendar_slot_max_time = sprintf('%02d:%02d:00', intdiv($display_max_minutes, 60), $display_max_minutes % 60);
    }

    return [
        'business_hours' => $calendar_business_hours,
        'slot_min_time' => $calendar_slot_min_time,
        'slot_max_time' => $calendar_slot_max_time,
    ];
}

/**
 * FullCalendar businessHours: doctor duty ∩ clinic per weekday, unioned across doctors.
 * Empty doctor list = clinic-only (same as legacy calendar shell).
 *
 * @param array<int> $doctor_ids
 * @return array<int, array{daysOfWeek: int[], startTime: string, endTime: string}>
 */
function dcmt_fc_business_hours_for_doctor_filter(PDO $pdo, array $doctor_ids)
{
    $doctor_ids = array_values(array_unique(array_filter(array_map('intval', $doctor_ids), function ($id) {
        return $id > 0;
    })));
    $clinic_cfg = dcmt_load_clinic_calendar_config($pdo);
    if (count($doctor_ids) === 0) {
        return $clinic_cfg['business_hours'];
    }

    $canonical_by_w = [
        0 => '2024-01-07',
        1 => '2024-01-01',
        2 => '2024-01-02',
        3 => '2024-01-03',
        4 => '2024-01-04',
        5 => '2024-01-05',
        6 => '2024-01-06',
    ];

    $fc = [];
    for ($w = 0; $w <= 6; $w++) {
        $date = $canonical_by_w[$w];
        $merged = [];
        foreach ($doctor_ids as $doc_id) {
            $duty = dcmt_get_doctor_duty_ranges($pdo, $doc_id, $date);
            $clinic = dcmt_get_clinic_working_ranges($pdo, $date);
            $eff = dcmt_intersect_time_ranges($duty, $clinic);
            $merged = array_merge($merged, $eff);
        }
        $merged = dcmt_union_time_ranges($merged);
        foreach ($merged as $r) {
            $st = substr((string)$r['dcmt_start_time'], 0, 5);
            $en = substr((string)$r['dcmt_end_time'], 0, 5);
            if (!preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $en)) {
                continue;
            }
            $fc[] = [
                'daysOfWeek' => [$w],
                'startTime' => $st,
                'endTime' => $en,
            ];
        }
    }
    return $fc;
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

function dcmt_get_busy_slots_for_operatory(PDO $pdo, $operatory_id, $date_ymd, $exclude_appointment_id = null)
{
    $day_start = $date_ymd . ' 00:00:00';
    $day_end = $date_ymd . ' 23:59:59';
    $sql = "
        SELECT
            COALESCE(dcmt_actual_start_at, dcmt_start_at) AS dcmt_start_at,
            COALESCE(dcmt_actual_end_at, dcmt_end_at) AS dcmt_end_at
        FROM dcmt_appointments
        WHERE dcmt_operatory_id = ?
          AND dcmt_status <> 'cancelled'
          AND COALESCE(dcmt_actual_start_at, dcmt_start_at) <= ?
          AND COALESCE(dcmt_actual_end_at, dcmt_end_at) >= ?
    ";
    $params = [(int)$operatory_id, $day_end, $day_start];
    if ($exclude_appointment_id !== null && (int)$exclude_appointment_id > 0) {
        $sql .= ' AND dcmt_id <> ?';
        $params[] = (int)$exclude_appointment_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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

/**
 * Handle POST from the appointment "today" board (start / end / cancel).
 * Returns true if the request was handled (redirect issued).
 */
function dcmt_try_handle_appointment_dashboard_post(PDO $pdo, array $current_user, string $redirect_url): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['dcmt_appointment_board'])) {
        return false;
    }

    $role = $current_user['dcmt_role'] ?? '';
    if (!in_array($role, ['admin', 'staff', 'assistant', 'doctor'], true)) {
        return false;
    }

    $m = dcmt_appointment_messages();
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if (!dcmt_verify_csrf_token($posted_token)) {
        dcmt_show_message(trans('common', 'invalid_token'), 'danger');
        dcmt_redirect($redirect_url);
        exit();
    }

    $is_doctor = $role === 'doctor';
    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));
    if ($appointment_id <= 0 || !in_array($action, ['start', 'end', 'cancel'], true)) {
        dcmt_show_message($m['invalid_request'], 'warning');
        dcmt_redirect($redirect_url);
        exit();
    }

    try {
        if ($action === 'cancel') {
            $sql = "UPDATE dcmt_appointments SET dcmt_status = 'cancelled' WHERE dcmt_id = ?";
            $params = [$appointment_id];
            if ($is_doctor) {
                $sql .= " AND dcmt_doctor_id = ?";
                $params[] = (int)$current_user['dcmt_id'];
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            dcmt_show_message($stmt->rowCount() > 0 ? $m['cancel_success'] : $m['save_failed'], $stmt->rowCount() > 0 ? 'success' : 'warning');
            dcmt_redirect($redirect_url);
            exit();
        }

        $now = dcmt_get_current_datetime();
        $doctor_guard_sql = '';
        $params = [$now, $appointment_id];
        if ($is_doctor) {
            $doctor_guard_sql = " AND dcmt_doctor_id = ?";
            $params[] = (int)$current_user['dcmt_id'];
        }

        if ($action === 'start') {
            $stmt = $pdo->prepare("
                UPDATE dcmt_appointments
                SET dcmt_actual_start_at = ?
                WHERE dcmt_id = ?
                  AND dcmt_status NOT IN ('cancelled', 'no_show')
                  AND dcmt_actual_start_at IS NULL
                  {$doctor_guard_sql}
            ");
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare("
                UPDATE dcmt_appointments
                SET dcmt_actual_end_at = ?, dcmt_status = 'completed'
                WHERE dcmt_id = ?
                  AND dcmt_status NOT IN ('cancelled', 'no_show')
                  AND dcmt_actual_start_at IS NOT NULL
                  AND dcmt_actual_end_at IS NULL
                  {$doctor_guard_sql}
            ");
            $stmt->execute($params);
        }

        dcmt_show_message($stmt->rowCount() > 0 ? $m['update_success'] : $m['save_failed'], $stmt->rowCount() > 0 ? 'success' : 'warning');
    } catch (PDOException $e) {
        error_log('Appointment dashboard action error: ' . $e->getMessage());
        dcmt_show_message($m['database_error'], 'danger');
    }

    dcmt_redirect($redirect_url);
    exit();
}
?>
