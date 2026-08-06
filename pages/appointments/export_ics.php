<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    http_response_code(401);
    exit('Unauthorized');
}

$ids = [];
if (isset($_GET['ids'])) {
    $raw_ids = $_GET['ids'];
    if (is_array($raw_ids)) {
        foreach ($raw_ids as $rid) {
            $id = (int)$rid;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } else {
        $parts = preg_split('/\s*,\s*/', (string)$raw_ids, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            $id = (int)$part;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
} else {
    $single_id = (int)($_GET['id'] ?? 0);
    if ($single_id > 0) {
        $ids[] = $single_id;
    }
}

$ids = array_values(array_unique($ids));
if (empty($ids)) {
    http_response_code(400);
    exit('Invalid appointment id(s)');
}

$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $doctor_sql = '';
    $is_limited_doctor = ($role === 'doctor') && !dcmt_is_admin();
    if ($is_limited_doctor) {
        $doctor_sql = ' AND a.dcmt_doctor_id = ?';
        $params[] = (int)$user['dcmt_id'];
    }

    $stmt = $dcmt_pdo->prepare("
        SELECT a.dcmt_id, a.dcmt_start_at, a.dcmt_end_at, a.dcmt_reason, a.dcmt_notes,
               a.dcmt_doctor_id, p.dcmt_patient_name, d.dcmt_full_name AS doctor_name,
               o.dcmt_name AS operatory_name
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        INNER JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
        WHERE a.dcmt_id IN ($placeholders)
        $doctor_sql
        ORDER BY a.dcmt_start_at ASC
    ");
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$appointments) {
        http_response_code(404);
        exit('Appointment(s) not found');
    }

    $clinic_tz = date_default_timezone_get();
    $dtstamp = gmdate('Ymd\THis\Z');
    $url = DCMT_APP_URL . '/pages/appointments/list.php';

    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//" . addcslashes(DCMT_APP_NAME, "\\,;") . "//EN\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= "X-WR-TIMEZONE:{$clinic_tz}\r\n";

    foreach ($appointments as $appointment) {
        $appointment_id = (int)($appointment['dcmt_id'] ?? 0);
        if ($appointment_id <= 0) {
            continue;
        }

        $start_local = new DateTime((string)$appointment['dcmt_start_at'], new DateTimeZone($clinic_tz));
        $end_local = new DateTime((string)$appointment['dcmt_end_at'], new DateTimeZone($clinic_tz));

        $patient_name = (string)($appointment['dcmt_patient_name'] ?? '');
        $doctor_name = (string)($appointment['doctor_name'] ?? '');
        $reason = trim((string)($appointment['dcmt_reason'] ?? ''));
        $notes = trim((string)($appointment['dcmt_notes'] ?? ''));

        $summary = 'Appointment - ' . $patient_name;
        if ($reason !== '') {
            $summary .= ' (' . $reason . ')';
        }

        $operatory_name = trim((string)($appointment['operatory_name'] ?? ''));
        $description = "Patient: {$patient_name}\\nDoctor: {$doctor_name}";
        if ($operatory_name !== '') {
            $description .= "\\nOperatory: {$operatory_name}";
        }
        if ($reason !== '') {
            $description .= "\\nReason: {$reason}";
        }
        if ($notes !== '') {
            $description .= "\\nNotes: {$notes}";
        }

        $summary_escaped = addcslashes($summary, "\\,;");
        $description_escaped = addcslashes($description, "\\,;");
        $uid = 'dcmt-appt-' . $appointment_id . '@' . preg_replace('/^https?:\/\//', '', DCMT_APP_URL);
        $dtstart = $start_local->format('Ymd\THis');
        $dtend = $end_local->format('Ymd\THis');

        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$dtstamp}\r\n";
        $ics .= "DTSTART;TZID={$clinic_tz}:{$dtstart}\r\n";
        $ics .= "DTEND;TZID={$clinic_tz}:{$dtend}\r\n";
        $ics .= "SUMMARY:{$summary_escaped}\r\n";
        $ics .= "DESCRIPTION:{$description_escaped}\r\n";
        $ics .= "URL:" . addcslashes($url, "\\,;") . "\r\n";
        $ics .= "END:VEVENT\r\n";
    }

    $ics .= "END:VCALENDAR\r\n";

    $filename = 'appointments-' . date('Ymd-His') . '.ics';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $ics;
} catch (Throwable $e) {
    error_log('Export ICS error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to export appointment');
}
?>
