<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    http_response_code(401);
    exit('Unauthorized');
}

$appointment_id = (int)($_GET['id'] ?? 0);
if ($appointment_id <= 0) {
    http_response_code(400);
    exit('Invalid appointment id');
}

$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT a.dcmt_id, a.dcmt_start_at, a.dcmt_end_at, a.dcmt_reason, a.dcmt_notes,
               a.dcmt_doctor_id, p.dcmt_patient_name, d.dcmt_full_name AS doctor_name
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        WHERE a.dcmt_id = ?
        LIMIT 1
    ");
    $stmt->execute([$appointment_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$appointment) {
        http_response_code(404);
        exit('Appointment not found');
    }

    if ($role === 'doctor' && (int)$appointment['dcmt_doctor_id'] !== (int)$user['dcmt_id']) {
        http_response_code(403);
        exit('Forbidden');
    }

    $start_local = new DateTime($appointment['dcmt_start_at']);
    $end_local = new DateTime($appointment['dcmt_end_at']);
    $start_utc = (clone $start_local)->setTimezone(new DateTimeZone('UTC'));
    $end_utc = (clone $end_local)->setTimezone(new DateTimeZone('UTC'));

    $patient_name = (string)($appointment['dcmt_patient_name'] ?? '');
    $doctor_name = (string)($appointment['doctor_name'] ?? '');
    $reason = trim((string)($appointment['dcmt_reason'] ?? ''));
    $notes = trim((string)($appointment['dcmt_notes'] ?? ''));

    $summary = 'Appointment - ' . $patient_name;
    if ($reason !== '') {
        $summary .= ' (' . $reason . ')';
    }

    $description = "Patient: {$patient_name}\\nDoctor: {$doctor_name}";
    if ($reason !== '') {
        $description .= "\\nReason: {$reason}";
    }
    if ($notes !== '') {
        $description .= "\\nNotes: {$notes}";
    }

    $summary_escaped = addcslashes($summary, "\\,;");
    $description_escaped = addcslashes($description, "\\,;");
    $uid = 'dcmt-appt-' . $appointment_id . '@' . preg_replace('/^https?:\/\//', '', DCMT_APP_URL);
    $dtstamp = gmdate('Ymd\THis\Z');
    $dtstart = $start_utc->format('Ymd\THis\Z');
    $dtend = $end_utc->format('Ymd\THis\Z');
    $url = DCMT_APP_URL . '/pages/appointments/list.php';

    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//" . addcslashes(DCMT_APP_NAME, "\\,;") . "//EN\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:{$uid}\r\n";
    $ics .= "DTSTAMP:{$dtstamp}\r\n";
    $ics .= "DTSTART:{$dtstart}\r\n";
    $ics .= "DTEND:{$dtend}\r\n";
    $ics .= "SUMMARY:{$summary_escaped}\r\n";
    $ics .= "DESCRIPTION:{$description_escaped}\r\n";
    $ics .= "URL:" . addcslashes($url, "\\,;") . "\r\n";
    $ics .= "END:VEVENT\r\n";
    $ics .= "END:VCALENDAR\r\n";

    $filename = 'appointment-' . $appointment_id . '.ics';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $ics;
} catch (Throwable $e) {
    error_log('Export ICS error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to export appointment');
}
?>
