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
        'delete_success' => trans('appointment', 'delete_success'),
        'delete_unauthorized' => trans('appointment', 'delete_unauthorized'),
        'delete_income_preserved' => trans('appointment', 'delete_income_preserved'),
        'appointment_started' => trans('appointment', 'appointment_started_success'),
        'appointment_ended' => trans('appointment', 'appointment_ended_success'),
        'appointment_already_started' => trans('appointment', 'appointment_already_started'),
        'appointment_already_ended' => trans('appointment', 'appointment_already_ended'),
        'appointment_not_started' => trans('appointment', 'appointment_not_started'),
        'appointment_state_changed' => trans('appointment', 'appointment_state_changed'),
        'reschedule_success' => trans('appointment', 'reschedule_success'),
        'reschedule_same_slot' => trans('appointment', 'reschedule_same_slot'),
        'save_failed' => trans('appointment', 'save_failed'),
        'system_error' => trans('appointment', 'system_error'),
        'database_error' => trans('appointment', 'database_error'),
        'duty_exceeds_clinic' => trans('appointment', 'duty_exceeds_clinic'),
        'duty_on_closed_clinic_day' => trans('appointment', 'duty_on_closed_clinic_day'),
        'lunch_outside_clinic' => trans('appointment', 'lunch_outside_clinic'),
        'lunch_covers_clinic' => trans('appointment', 'lunch_covers_clinic'),
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

function dcmt_minutes_to_his(int $minutes): string
{
    $minutes = max(0, min(24 * 60, $minutes));
    return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
}

function dcmt_normalize_setting_time(string $value, string $default_his): string
{
    $mins = dcmt_time_string_to_minutes($value);
    if ($mins === null) {
        return $default_his;
    }
    return dcmt_minutes_to_his($mins);
}

function dcmt_post_flag_is_on($value): bool
{
    return $value === '1' || $value === 1 || $value === true || $value === 'true';
}

/**
 * Clinic working-hours settings keyed by weekday (0=Sun .. 6=Sat).
 *
 * @return array<int, array{dcmt_weekday: int, dcmt_start_time: string, dcmt_end_time: string, dcmt_is_active: int, dcmt_lunch_start_time: string, dcmt_lunch_end_time: string, dcmt_lunch_active: int}>
 */
function dcmt_load_clinic_hours_map(PDO $pdo): array
{
    $map = [];
    for ($day = 0; $day <= 6; $day++) {
        $map[$day] = [
            'dcmt_weekday' => $day,
            'dcmt_start_time' => '09:00:00',
            'dcmt_end_time' => '17:00:00',
            'dcmt_is_active' => 1,
            'dcmt_lunch_start_time' => '13:00:00',
            'dcmt_lunch_end_time' => '14:00:00',
            'dcmt_lunch_active' => 0,
        ];
    }

    try {
        $stmt = $pdo->query("
            SELECT dcmt_setting_key, dcmt_setting_value
            FROM dcmt_settings
            WHERE dcmt_setting_key LIKE 'clinic_working_hours_%'
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        error_log('dcmt_load_clinic_hours_map: ' . $e->getMessage());
        return $map;
    }

    foreach ($rows as $setting_row) {
        $key = (string)($setting_row['dcmt_setting_key'] ?? '');
        $value = trim((string)($setting_row['dcmt_setting_value'] ?? ''));
        if (!preg_match('/^clinic_working_hours_(\d+)_(lunch_start|lunch_end|lunch_active|start|end|active)$/', $key, $matches)) {
            continue;
        }
        $day = (int)$matches[1];
        if ($day < 0 || $day > 6 || !isset($map[$day])) {
            continue;
        }
        $field = $matches[2];
        if ($field === 'start') {
            $map[$day]['dcmt_start_time'] = dcmt_normalize_setting_time($value, '09:00:00');
        } elseif ($field === 'end') {
            $map[$day]['dcmt_end_time'] = dcmt_normalize_setting_time($value, '17:00:00');
        } elseif ($field === 'active') {
            $map[$day]['dcmt_is_active'] = ($value === '1') ? 1 : 0;
        } elseif ($field === 'lunch_start') {
            $map[$day]['dcmt_lunch_start_time'] = dcmt_normalize_setting_time($value, '13:00:00');
        } elseif ($field === 'lunch_end') {
            $map[$day]['dcmt_lunch_end_time'] = dcmt_normalize_setting_time($value, '14:00:00');
        } else {
            $map[$day]['dcmt_lunch_active'] = ($value === '1') ? 1 : 0;
        }
    }

    return $map;
}

/**
 * Remove a break interval from duty-style ranges (H:i:s).
 *
 * @param array<int, array{dcmt_start_time: string, dcmt_end_time: string}> $ranges
 * @return array<int, array{dcmt_start_time: string, dcmt_end_time: string}>
 */
function dcmt_exclude_interval_from_ranges(array $ranges, int $break_start, int $break_end): array
{
    if ($break_start >= $break_end) {
        return $ranges;
    }
    $out = [];
    foreach ($ranges as $range) {
        $a = dcmt_time_string_to_minutes((string)($range['dcmt_start_time'] ?? ''));
        $b = dcmt_time_string_to_minutes((string)($range['dcmt_end_time'] ?? ''));
        if ($a === null || $b === null || $a >= $b) {
            continue;
        }
        $left_end = min($b, $break_start);
        if ($a < $left_end) {
            $out[] = [
                'dcmt_start_time' => dcmt_minutes_to_his($a),
                'dcmt_end_time' => dcmt_minutes_to_his($left_end),
            ];
        }
        $right_start = max($a, $break_end);
        if ($right_start < $b) {
            $out[] = [
                'dcmt_start_time' => dcmt_minutes_to_his($right_start),
                'dcmt_end_time' => dcmt_minutes_to_his($b),
            ];
        }
    }
    return $out;
}

/**
 * @param array{dcmt_start_time?: string, dcmt_end_time?: string, dcmt_is_active?: int, dcmt_lunch_start_time?: string, dcmt_lunch_end_time?: string, dcmt_lunch_active?: int} $row
 * @return array<int, array{dcmt_start_time: string, dcmt_end_time: string}>
 */
function dcmt_clinic_hours_row_to_ranges(array $row): array
{
    if ((int)($row['dcmt_is_active'] ?? 0) !== 1) {
        return [];
    }
    $start = (string)($row['dcmt_start_time'] ?? '09:00:00');
    $end = (string)($row['dcmt_end_time'] ?? '17:00:00');
    $start_mins = dcmt_time_string_to_minutes($start);
    $end_mins = dcmt_time_string_to_minutes($end);
    if ($start_mins === null || $end_mins === null || $start_mins >= $end_mins) {
        return [];
    }
    $ranges = [[
        'dcmt_start_time' => dcmt_minutes_to_his($start_mins),
        'dcmt_end_time' => dcmt_minutes_to_his($end_mins),
    ]];
    if ((int)($row['dcmt_lunch_active'] ?? 0) !== 1) {
        return $ranges;
    }
    $lunch_start = dcmt_time_string_to_minutes((string)($row['dcmt_lunch_start_time'] ?? ''));
    $lunch_end = dcmt_time_string_to_minutes((string)($row['dcmt_lunch_end_time'] ?? ''));
    if ($lunch_start === null || $lunch_end === null || $lunch_start >= $lunch_end) {
        return $ranges;
    }
    return dcmt_exclude_interval_from_ranges($ranges, $lunch_start, $lunch_end);
}

function dcmt_get_clinic_working_ranges(PDO $pdo, $date_ymd)
{
    $weekday = (int)date('w', strtotime($date_ymd));
    $map = dcmt_load_clinic_hours_map($pdo);
    return dcmt_clinic_hours_row_to_ranges($map[$weekday] ?? []);
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
        $duty_active = dcmt_post_flag_is_on($de['active'] ?? null);
        if (!$duty_active) {
            continue;
        }
        $clinic_active = dcmt_post_flag_is_on($ce['active'] ?? null);
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

/**
 * Active lunch windows on clinic-open days must sit inside that day's clinic hours
 * and must leave at least one bookable interval.
 *
 * @param array<int|string, array<string, mixed>> $clinic_rows
 * @return string|null appointment translation key when invalid
 */
function dcmt_clinic_lunch_post_is_valid(array $clinic_rows): ?string
{
    for ($w = 0; $w <= 6; $w++) {
        $ce = $clinic_rows[$w] ?? [];
        $lunch_active = dcmt_post_flag_is_on($ce['lunch_active'] ?? null);
        if (!$lunch_active) {
            continue;
        }
        $clinic_active = dcmt_post_flag_is_on($ce['active'] ?? null);
        if (!$clinic_active) {
            continue;
        }
        $cs = trim((string)($ce['start'] ?? '09:00'));
        $cen = trim((string)($ce['end'] ?? '17:00'));
        $ls = trim((string)($ce['lunch_start'] ?? '13:00'));
        $le = trim((string)($ce['lunch_end'] ?? '14:00'));
        $cS = dcmt_time_string_to_minutes($cs);
        $cE = dcmt_time_string_to_minutes($cen);
        $lS = dcmt_time_string_to_minutes($ls);
        $lE = dcmt_time_string_to_minutes($le);
        if ($cS === null || $cE === null || $lS === null || $lE === null) {
            return 'invalid_datetime';
        }
        if ($lS >= $lE) {
            return 'start_before_end';
        }
        if ($lS < $cS || $lE > $cE) {
            return 'lunch_outside_clinic';
        }
        $remaining = dcmt_exclude_interval_from_ranges([
            [
                'dcmt_start_time' => dcmt_minutes_to_his($cS),
                'dcmt_end_time' => dcmt_minutes_to_his($cE),
            ],
        ], $lS, $lE);
        if (count($remaining) === 0) {
            return 'lunch_covers_clinic';
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
    $clinic_map = dcmt_load_clinic_hours_map($pdo);

    $min_minutes = null;
    $max_minutes = null;
    foreach ($clinic_map as $day_cfg) {
        if ((int)($day_cfg['dcmt_is_active'] ?? 0) !== 1) {
            continue;
        }
        $start_minutes = dcmt_time_string_to_minutes((string)($day_cfg['dcmt_start_time'] ?? '09:00:00'));
        $end_minutes = dcmt_time_string_to_minutes((string)($day_cfg['dcmt_end_time'] ?? '17:00:00'));
        if ($start_minutes === null || $end_minutes === null || $start_minutes >= $end_minutes) {
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
        $ranges = dcmt_clinic_hours_row_to_ranges($clinic_map[$day] ?? []);
        foreach ($ranges as $range) {
            $start_hm = substr((string)($range['dcmt_start_time'] ?? ''), 0, 5);
            $end_hm = substr((string)($range['dcmt_end_time'] ?? ''), 0, 5);
            if (!preg_match('/^\d{2}:\d{2}$/', $start_hm) || !preg_match('/^\d{2}:\d{2}$/', $end_hm)) {
                continue;
            }
            $calendar_business_hours[] = [
                'daysOfWeek' => [$day],
                'startTime' => $start_hm,
                'endTime' => $end_hm,
            ];
        }
    }

    if ($min_minutes !== null && $max_minutes !== null && $min_minutes < $max_minutes) {
        $calendar_slot_min_time = dcmt_minutes_to_his($min_minutes);
        $display_max_minutes = min(24 * 60, $max_minutes + 30);
        $calendar_slot_max_time = dcmt_minutes_to_his($display_max_minutes);
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
 * Income records billed against an appointment, newest first.
 *
 * @return list<array<string, mixed>>
 */
function dcmt_appointment_income_fetch(PDO $pdo, int $appointment_id): array
{
    if ($appointment_id <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT i.dcmt_id, i.dcmt_amount, i.dcmt_total_paid_amount, i.dcmt_total_pending_amount,
                   i.dcmt_transaction_date, i.dcmt_description, i.dcmt_created_by, i.dcmt_created_at,
                   ps.dcmt_name AS payment_status_name,
                   u.dcmt_full_name AS doctor_name
            FROM dcmt_income i
            LEFT JOIN dcmt_income_payment_status ps ON ps.dcmt_id = i.dcmt_payment_status_id
            LEFT JOIN dcmt_users u ON u.dcmt_id = i.dcmt_user_id
            WHERE i.dcmt_appointment_id = ?
            ORDER BY i.dcmt_id DESC
        ");
        $stmt->execute([$appointment_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('dcmt_appointment_income_fetch failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Total billed, paid and outstanding for an appointment's income records.
 *
 * @param list<array<string, mixed>>|null $rows Pre-fetched rows, optional.
 * @return array{count: int, billed: float, paid: float, pending: float}
 */
function dcmt_appointment_billing_totals(PDO $pdo, int $appointment_id, ?array $rows = null): array
{
    $rows = $rows ?? dcmt_appointment_income_fetch($pdo, $appointment_id);
    $billed = 0.0;
    $paid = 0.0;
    $pending = 0.0;
    foreach ($rows as $row) {
        $billed += (float)($row['dcmt_amount'] ?? 0);
        $paid += (float)($row['dcmt_total_paid_amount'] ?? 0);
        $pending += (float)($row['dcmt_total_pending_amount'] ?? 0);
    }

    return [
        'count' => count($rows),
        'billed' => round($billed, 2),
        'paid' => round($paid, 2),
        'pending' => round($pending, 2),
    ];
}

/**
 * Resolve the acting user for an appointment audit entry.
 *
 * @return array{id: int, name: string, role: string}
 */
function dcmt_appointment_actor(): array
{
    $user = function_exists('dcmt_get_current_user') ? dcmt_get_current_user() : null;
    if (!is_array($user)) {
        return ['id' => 0, 'name' => '', 'role' => ''];
    }

    $name = trim((string)($user['dcmt_full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($user['dcmt_username'] ?? ''));
    }

    return [
        'id' => (int)($user['dcmt_id'] ?? 0),
        'name' => $name,
        'role' => (string)($user['dcmt_role'] ?? ''),
    ];
}

/**
 * Append an entry to the appointment audit trail.
 *
 * Never throws: auditing must not break a clinical action.
 *
 * @param array<string,mixed> $meta Optional actor/source/transition details.
 */
function dcmt_appointment_audit_log(PDO $pdo, int $appointment_id, string $action, array $meta = []): bool
{
    $action = strtolower(trim($action));
    if ($appointment_id <= 0 || $action === '') {
        return false;
    }

    $actor = dcmt_appointment_actor();
    $user_id = (int)($meta['actor_id'] ?? $actor['id']);
    $user_name = trim((string)($meta['actor_name'] ?? ''));
    if ($user_name === '') {
        $user_name = $actor['name'];
    }
    $user_role = trim((string)($meta['actor_role'] ?? $actor['role']));

    $ip = '';
    if (function_exists('dcmt_audit_client_ip')) {
        $ip = dcmt_audit_client_ip();
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = (string)$_SERVER['REMOTE_ADDR'];
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO dcmt_appointment_audit
                (dcmt_appointment_id, dcmt_action, dcmt_user_id, dcmt_user_name, dcmt_user_role,
                 dcmt_source, dcmt_ip, dcmt_previous_status, dcmt_new_status,
                 dcmt_previous_value, dcmt_new_value, dcmt_details, dcmt_created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $appointment_id,
            $action,
            $user_id > 0 ? $user_id : null,
            $user_name !== '' ? $user_name : null,
            $user_role !== '' ? $user_role : null,
            trim((string)($meta['source'] ?? '')) !== '' ? trim((string)($meta['source'])) : null,
            $ip !== '' ? $ip : null,
            trim((string)($meta['previous_status'] ?? '')) !== '' ? trim((string)($meta['previous_status'])) : null,
            trim((string)($meta['new_status'] ?? '')) !== '' ? trim((string)($meta['new_status'])) : null,
            !empty($meta['previous_value']) ? (string)$meta['previous_value'] : null,
            !empty($meta['new_value']) ? (string)$meta['new_value'] : null,
            trim((string)($meta['details'] ?? '')) !== '' ? trim((string)($meta['details'])) : null,
            dcmt_get_current_datetime(),
        ]);

        return true;
    } catch (PDOException $e) {
        error_log('dcmt_appointment_audit_log failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Read the audit trail for an appointment, newest first.
 *
 * @return array<int,array<string,mixed>>
 */
function dcmt_appointment_audit_fetch(PDO $pdo, int $appointment_id, int $limit = 100): array
{
    if ($appointment_id <= 0) {
        return [];
    }

    $limit = max(1, min(500, $limit));

    try {
        $stmt = $pdo->prepare("
            SELECT dcmt_id, dcmt_appointment_id, dcmt_action, dcmt_user_id, dcmt_user_name,
                   dcmt_user_role, dcmt_source, dcmt_ip, dcmt_previous_status, dcmt_new_status,
                   dcmt_previous_value, dcmt_new_value, dcmt_details, dcmt_created_at
            FROM dcmt_appointment_audit
            WHERE dcmt_appointment_id = ?
            ORDER BY dcmt_created_at DESC, dcmt_id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$appointment_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('dcmt_appointment_audit_fetch failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Permanently delete an appointment. Super Admin only.
 *
 * Cancelling keeps history and is what every other role does. This path is the
 * genuine "remove it from the system" action, so it:
 *  - requires the Super Admin capability,
 *  - writes a `deleted` entry to the append-only appointment audit trail,
 *  - never destroys financial records: linked income rows are detached, not removed,
 *  - clears optional references (messaging conversations) when present.
 *
 * @param array<string,mixed>|null $actor Defaults to the current user.
 * @return array{ok: bool, message: string, detached_income: int}
 */
function dcmt_appointment_delete(PDO $pdo, int $appointment_id, ?array $actor = null): array
{
    $messages = dcmt_appointment_messages();
    if ($appointment_id <= 0) {
        return ['ok' => false, 'message' => $messages['invalid_request'], 'detached_income' => 0];
    }
    if (!dcmt_can_delete_appointments($actor)) {
        return ['ok' => false, 'message' => $messages['delete_unauthorized'], 'detached_income' => 0];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT a.*, p.dcmt_patient_name, d.dcmt_full_name AS doctor_name,
                   o.dcmt_name AS operatory_name
            FROM dcmt_appointments a
            LEFT JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
            LEFT JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
            LEFT JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
            WHERE a.dcmt_id = ?
            LIMIT 1
        ");
        $stmt->execute([$appointment_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('dcmt_appointment_delete (load): ' . $e->getMessage());
        return ['ok' => false, 'message' => $messages['database_error'], 'detached_income' => 0];
    }

    if (!$appointment) {
        return ['ok' => false, 'message' => $messages['invalid_request'], 'detached_income' => 0];
    }

    $patient_name = trim((string)($appointment['dcmt_patient_name'] ?? ''));
    $doctor_name = trim((string)($appointment['doctor_name'] ?? ''));
    $operatory_name = trim((string)($appointment['operatory_name'] ?? ''));
    $start_at = trim((string)($appointment['dcmt_start_at'] ?? ''));
    $status = dcmt_normalize_appointment_status((string)($appointment['dcmt_status'] ?? 'scheduled'));

    $detached_income = 0;

    try {
        $pdo->beginTransaction();

        // Financial history survives: the income row stays, only the link goes.
        try {
            $detach_income = $pdo->prepare("UPDATE dcmt_income SET dcmt_appointment_id = NULL WHERE dcmt_appointment_id = ?");
            $detach_income->execute([$appointment_id]);
            $detached_income = $detach_income->rowCount();
        } catch (PDOException $e) {
            // Older installs may not have the linkage column yet.
            error_log('dcmt_appointment_delete (income detach): ' . $e->getMessage());
        }

        // Messaging threads keep their content but lose the dead appointment reference.
        try {
            $has_conv_col = $pdo->query("SHOW COLUMNS FROM dcmt_conversations LIKE 'dcmt_appointment_id'")->fetch(PDO::FETCH_ASSOC);
            if ($has_conv_col) {
                $detach_conv = $pdo->prepare("UPDATE dcmt_conversations SET dcmt_appointment_id = NULL WHERE dcmt_appointment_id = ?");
                $detach_conv->execute([$appointment_id]);
            }
        } catch (PDOException $e) {
            error_log('dcmt_appointment_delete (conversation detach): ' . $e->getMessage());
        }

        // Audit before the row disappears so the trail records who removed it.
        $summary = trim(implode(' · ', array_filter([
            $patient_name,
            $doctor_name,
            $operatory_name,
            $start_at !== '' ? $start_at : '',
            $status,
        ], static function ($part) {
            return $part !== '';
        })));
        dcmt_appointment_audit_log($pdo, $appointment_id, 'deleted', [
            'previous_status' => $status,
            'previous_value' => $start_at,
            'details' => $summary,
        ]);

        $delete_stmt = $pdo->prepare("DELETE FROM dcmt_appointments WHERE dcmt_id = ?");
        $delete_stmt->execute([$appointment_id]);
        if ($delete_stmt->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => $messages['appointment_state_changed'], 'detached_income' => 0];
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('dcmt_appointment_delete: ' . $e->getMessage());
        return ['ok' => false, 'message' => $messages['database_error'], 'detached_income' => 0];
    }

    $message = $messages['delete_success'];
    if ($detached_income > 0) {
        $message .= ' ' . sprintf($messages['delete_income_preserved'], $detached_income);
    }

    return ['ok' => true, 'message' => $message, 'detached_income' => $detached_income];
}

/**
 * Normalize a calendar range boundary into a MySQL DATETIME string.
 *
 * FullCalendar sends ISO-8601 values such as `2026-10-26T00:00:00+05:30`.
 * MySQL cannot compare DATETIME columns against that shape, which silently
 * produced empty day views. The wall-clock parts are preserved (the app stores
 * clinic-local times), the `T` separator and offset are dropped.
 */
function dcmt_normalize_calendar_bound($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::(\d{2}))?/', $value, $matches)) {
        $seconds = isset($matches[3]) && $matches[3] !== '' ? $matches[3] : '00';
        return $matches[1] . ' ' . $matches[2] . ':' . $seconds;
    }

    return $value;
}

/**
 * Build a stable token describing the timer-relevant state of an appointment.
 *
 * Two devices compare this token before acting so a stale calendar cannot
 * start/finish an appointment that another device already moved on.
 *
 * @param array<string,mixed> $row Raw dcmt_appointments row.
 */
function dcmt_appointment_state_token(array $row): string
{
    return sha1(implode('|', [
        (string)($row['dcmt_id'] ?? ''),
        (string)($row['dcmt_status'] ?? ''),
        (string)($row['dcmt_actual_start_at'] ?? ''),
        (string)($row['dcmt_actual_end_at'] ?? ''),
        (string)($row['dcmt_started_by'] ?? ''),
        (string)($row['dcmt_ended_by'] ?? ''),
        (string)($row['dcmt_updated_at'] ?? ''),
    ]));
}

/**
 * Shape an appointment row into the shared timer state payload used by the
 * calendar, the action endpoints and the lightweight polling endpoint.
 *
 * @param array<string,mixed> $row Raw dcmt_appointments row.
 * @return array<string,mixed>
 */
function dcmt_appointment_timer_state(array $row): array
{
    $status = dcmt_normalize_appointment_status((string)($row['dcmt_status'] ?? ''));
    $actual_start = trim((string)($row['dcmt_actual_start_at'] ?? ''));
    $actual_end = trim((string)($row['dcmt_actual_end_at'] ?? ''));
    $has_actual_start = $actual_start !== '';
    $has_actual_end = $actual_end !== '';
    $is_cancelled = $status === 'cancelled';
    $is_completed = $status === 'completed';

    return [
        'id' => (int)($row['dcmt_id'] ?? 0),
        'status' => $status,
        'raw_status' => (string)($row['dcmt_status'] ?? ''),
        'actual_start_at' => $actual_start,
        'actual_end_at' => $actual_end,
        'actual_start_time' => $has_actual_start ? date('H:i', strtotime($actual_start)) : '',
        'actual_end_time' => $has_actual_end ? date('H:i', strtotime($actual_end)) : '',
        'actual_start_label' => $has_actual_start ? date('h:i A', strtotime($actual_start)) : '',
        'actual_end_label' => $has_actual_end ? date('h:i A', strtotime($actual_end)) : '',
        'has_actual_start' => $has_actual_start,
        'has_actual_end' => $has_actual_end,
        'is_in_progress' => $has_actual_start && !$has_actual_end && !$is_cancelled && !$is_completed,
        'can_start' => !$is_cancelled && !$is_completed && !$has_actual_start,
        'can_end' => !$is_cancelled && !$is_completed && $has_actual_start && !$has_actual_end,
        'can_cancel' => !$is_cancelled && !$is_completed,
        'started_by_id' => (int)($row['dcmt_started_by'] ?? 0),
        'started_by_name' => trim((string)($row['started_by_name'] ?? '')),
        'ended_by_id' => (int)($row['dcmt_ended_by'] ?? 0),
        'ended_by_name' => trim((string)($row['ended_by_name'] ?? '')),
        'cancelled_by_id' => (int)($row['dcmt_cancelled_by'] ?? 0),
        'updated_at' => (string)($row['dcmt_updated_at'] ?? ''),
        'token' => dcmt_appointment_state_token($row),
    ];
}

/**
 * Atomically read the current timer state of an appointment.
 *
 * @return array<string,mixed>|null Null when the appointment does not exist.
 */
function dcmt_appointment_fetch_timer_state(PDO $pdo, int $appointment_id): ?array
{
    $stmt = $pdo->prepare("
        SELECT a.dcmt_id, a.dcmt_status, a.dcmt_actual_start_at, a.dcmt_actual_end_at,
               a.dcmt_started_by, a.dcmt_ended_by, a.dcmt_cancelled_by, a.dcmt_updated_at,
               us.dcmt_full_name AS started_by_name,
               ue.dcmt_full_name AS ended_by_name
        FROM dcmt_appointments a
        LEFT JOIN dcmt_users us ON us.dcmt_id = a.dcmt_started_by
        LEFT JOIN dcmt_users ue ON ue.dcmt_id = a.dcmt_ended_by
        WHERE a.dcmt_id = ?
        LIMIT 1
    ");
    $stmt->execute([$appointment_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? dcmt_appointment_timer_state($row) : null;
}

/**
 * Start or finish an appointment with cross-device safety.
 *
 * The row is selected with FOR UPDATE inside a transaction, so two devices
 * firing at the same moment are serialized: the second one observes the
 * committed state and is answered with an `already_started` / `already_ended`
 * / `not_started` result instead of writing a second timestamp.
 *
 * An optional `expected_token` provides optimistic concurrency: when the
 * caller acted on a stale screen the transition is refused with `stale` and
 * the authoritative state, letting the UI reconcile immediately.
 *
 * @param array{expected_token?: string, doctor_id?: int, action_by?: int, source?: string, actor_name?: string, actor_role?: string} $options
 * @return array{ok: bool, code: string, message: string, state: array<string,mixed>|null, changed: bool}
 */
function dcmt_appointment_timer_transition(PDO $pdo, int $appointment_id, string $action, array $options = []): array
{
    $m = dcmt_appointment_messages();
    $action = strtolower(trim($action));
    $expected_token = trim((string)($options['expected_token'] ?? ''));
    $doctor_id = (int)($options['doctor_id'] ?? 0);
    $source = trim((string)($options['source'] ?? ''));
    $actor = dcmt_appointment_actor();
    $actor_id = (int)($options['action_by'] ?? $actor['id']);
    $actor_name = trim((string)($options['actor_name'] ?? ''));
    if ($actor_name === '') {
        $actor_name = $actor['name'];
    }
    $actor_role = trim((string)($options['actor_role'] ?? $actor['role']));

    if ($appointment_id <= 0 || !in_array($action, ['start', 'end'], true)) {
        return ['ok' => false, 'code' => 'invalid_action', 'message' => $m['invalid_request'], 'state' => null, 'changed' => false];
    }

    $owns_transaction = !$pdo->inTransaction();

    try {
        if ($owns_transaction) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare("
            SELECT dcmt_id, dcmt_status, dcmt_doctor_id, dcmt_actual_start_at, dcmt_actual_end_at,
                   dcmt_started_by, dcmt_ended_by, dcmt_cancelled_by, dcmt_updated_at
            FROM dcmt_appointments
            WHERE dcmt_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$appointment_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            if ($owns_transaction) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'code' => 'not_found', 'message' => $m['invalid_request'], 'state' => null, 'changed' => false];
        }

        if ($doctor_id > 0 && (int)$row['dcmt_doctor_id'] !== $doctor_id) {
            if ($owns_transaction) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'code' => 'forbidden', 'message' => $m['unauthorized'], 'state' => dcmt_appointment_timer_state($row), 'changed' => false];
        }

        $state = dcmt_appointment_timer_state($row);

        if ($expected_token !== '' && !hash_equals($state['token'], $expected_token)) {
            if ($owns_transaction) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'code' => 'stale', 'message' => $m['appointment_state_changed'], 'state' => $state, 'changed' => false];
        }

        if ($state['status'] === 'cancelled') {
            if ($owns_transaction) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'code' => 'cancelled', 'message' => $m['cancelled_locked'], 'state' => $state, 'changed' => false];
        }

        if ($action === 'start') {
            if ($state['has_actual_end'] || $state['status'] === 'completed') {
                if ($owns_transaction) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'code' => 'already_ended', 'message' => $m['appointment_already_ended'], 'state' => $state, 'changed' => false];
            }
            if ($state['has_actual_start']) {
                if ($owns_transaction) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'code' => 'already_started', 'message' => $m['appointment_already_started'], 'state' => $state, 'changed' => false];
            }

            $now = dcmt_get_current_datetime();
            $upd = $pdo->prepare("
                UPDATE dcmt_appointments
                SET dcmt_actual_start_at = ?, dcmt_started_by = ?
                WHERE dcmt_id = ?
                  AND dcmt_actual_start_at IS NULL
                  AND dcmt_actual_end_at IS NULL
                  AND dcmt_status NOT IN ('cancelled', 'completed', 'no_show')
            ");
            $upd->execute([$now, $actor_id > 0 ? $actor_id : null, $appointment_id]);
            $changed = $upd->rowCount() > 0;
            $code = 'started';
            $message = $m['appointment_started'];
            $audit_action = 'started';
            $audit_new_value = $now;
        } else {
            if ($state['has_actual_end'] || $state['status'] === 'completed') {
                if ($owns_transaction) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'code' => 'already_ended', 'message' => $m['appointment_already_ended'], 'state' => $state, 'changed' => false];
            }
            if (!$state['has_actual_start']) {
                if ($owns_transaction) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'code' => 'not_started', 'message' => $m['appointment_not_started'], 'state' => $state, 'changed' => false];
            }

            $now = dcmt_get_current_datetime();
            $upd = $pdo->prepare("
                UPDATE dcmt_appointments
                SET dcmt_actual_end_at = ?, dcmt_status = 'completed', dcmt_ended_by = ?
                WHERE dcmt_id = ?
                  AND dcmt_actual_start_at IS NOT NULL
                  AND dcmt_actual_end_at IS NULL
                  AND dcmt_status NOT IN ('cancelled', 'completed', 'no_show')
            ");
            $upd->execute([$now, $actor_id > 0 ? $actor_id : null, $appointment_id]);
            $changed = $upd->rowCount() > 0;
            $code = 'ended';
            $message = $m['appointment_ended'];
            $audit_action = 'ended';
            $audit_new_value = $now;
        }

        if (!$changed) {
            // The guard rejected the write: the row moved between the lock and
            // the update (or a same-second no-op). Report the live state.
            if ($owns_transaction) {
                $pdo->rollBack();
            }
            $fresh = dcmt_appointment_fetch_timer_state($pdo, $appointment_id);
            return [
                'ok' => false,
                'code' => ($fresh && $fresh['has_actual_start']) ? 'already_started' : 'not_started',
                'message' => $m['appointment_state_changed'],
                'state' => $fresh,
                'changed' => false,
            ];
        }

        // Append the audit trail entry inside the same transaction as the state
        // change, so who-started/who-ended can never be missing or orphaned.
        dcmt_appointment_audit_log($pdo, $appointment_id, $audit_action, [
            'actor_id' => $actor_id,
            'actor_name' => $actor_name,
            'actor_role' => $actor_role,
            'source' => $source,
            'previous_status' => $state['status'],
            'new_status' => $audit_action === 'ended' ? 'completed' : $state['status'],
            'previous_value' => $audit_action === 'ended' ? $state['actual_start_at'] : '',
            'new_value' => $audit_new_value,
            'details' => $audit_action === 'ended'
                ? 'Session closed'
                : 'Session started',
        ]);

        if ($owns_transaction) {
            $pdo->commit();
        }

        // Re-read with the actor names joined so clients can show who acted.
        $fresh_state = dcmt_appointment_fetch_timer_state($pdo, $appointment_id);

        return [
            'ok' => true,
            'code' => $code,
            'message' => $message,
            'state' => $fresh_state ?: $state,
            'changed' => true,
        ];
    } catch (PDOException $e) {
        if ($owns_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Appointment timer transition error: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'db_error', 'message' => $m['database_error'], 'state' => null, 'changed' => false];
    }
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
    if (!in_array($role, ['super_admin', 'admin', 'staff', 'assistant', 'doctor'], true)) {
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
    $is_limited_doctor = $is_doctor && !dcmt_is_admin();
    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));
    if ($appointment_id <= 0 || !in_array($action, ['start', 'end', 'cancel'], true)) {
        dcmt_show_message($m['invalid_request'], 'warning');
        dcmt_redirect($redirect_url);
        exit();
    }

    try {
        if ($action === 'cancel') {
            $sql = "UPDATE dcmt_appointments
                    SET dcmt_status = 'cancelled', dcmt_cancelled_by = ?
                    WHERE dcmt_id = ?
                      AND dcmt_status NOT IN ('cancelled', 'completed', 'no_show')";
            $params = [
                (int)($current_user['dcmt_id'] ?? 0) ?: null,
                $appointment_id,
            ];
            if ($is_limited_doctor) {
                $sql .= " AND dcmt_doctor_id = ?";
                $params[] = (int)$current_user['dcmt_id'];
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $cancelled = $stmt->rowCount() > 0;
            if ($cancelled) {
                dcmt_appointment_audit_log($pdo, $appointment_id, 'cancelled', [
                    'actor_id' => (int)($current_user['dcmt_id'] ?? 0),
                    'actor_name' => (string)($current_user['dcmt_full_name'] ?? ''),
                    'actor_role' => $role,
                    'source' => 'appointment_board_form',
                    'new_status' => 'cancelled',
                ]);
            }
            dcmt_show_message($cancelled ? $m['cancel_success'] : $m['appointment_state_changed'], $cancelled ? 'success' : 'warning');
            dcmt_redirect($redirect_url);
            exit();
        }

        $result = dcmt_appointment_timer_transition($pdo, $appointment_id, $action, [
            'doctor_id' => $is_limited_doctor ? (int)$current_user['dcmt_id'] : 0,
            'action_by' => (int)($current_user['dcmt_id'] ?? 0),
            'actor_name' => (string)($current_user['dcmt_full_name'] ?? ''),
            'actor_role' => $role,
            'source' => 'appointment_board_form',
        ]);

        dcmt_show_message(
            $result['ok'] ? $m['update_success'] : (string)$result['message'],
            $result['ok'] ? 'success' : 'warning'
        );
    } catch (PDOException $e) {
        error_log('Appointment dashboard action error: ' . $e->getMessage());
        dcmt_show_message($m['database_error'], 'danger');
    }

    dcmt_redirect($redirect_url);
    exit();
}
?>
