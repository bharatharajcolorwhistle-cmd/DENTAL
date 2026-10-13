<?php
/**
 * Export Patients without Upcoming Appointments to CSV.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';

$where = ["p.dcmt_status = 'active'"];
$params = [];
if ($search !== '') {
    $where[] = '(p.dcmt_patient_name LIKE ? OR p.dcmt_phone LIKE ? OR p.dcmt_email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$where[] = "NOT EXISTS (
    SELECT 1
    FROM dcmt_appointments a
    WHERE a.dcmt_patient_id = p.dcmt_id
      AND a.dcmt_end_at >= NOW()
      AND a.dcmt_status NOT IN ('cancelled', 'completed', 'no_show')
)";
$where_sql = 'WHERE ' . implode(' AND ', $where);

$patients = [];

try {
    $sql = "
        SELECT
            p.dcmt_id,
            p.dcmt_patient_name,
            p.dcmt_phone,
            p.dcmt_email,
            (
                SELECT MAX(a2.dcmt_start_at)
                FROM dcmt_appointments a2
                WHERE a2.dcmt_patient_id = p.dcmt_id
                  AND a2.dcmt_status NOT IN ('cancelled')
            ) AS last_appointment_at
        FROM dcmt_patients p
        {$where_sql}
        ORDER BY p.dcmt_patient_name ASC
    ";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    dcmt_log_activity('Patients without upcoming appointments exported to CSV - ' . count($patients) . ' records', 'patient_no_upcoming_exported');
} catch (PDOException $e) {
    error_log('No upcoming appointments export error: ' . $e->getMessage());
    dcmt_show_message(trans('patient', 'database_error'), 'error');
    dcmt_redirect('no_upcoming_appointments.php');
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="patients_without_upcoming_appointments_' . date('Y-m-d_H-i-s') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

$headers = [
    trans('patient', 'patient'),
    trans('patient', 'phone'),
    trans('patient', 'email'),
    trans('patient', 'last_appointment'),
];
fputcsv($output, $headers, ',', '"', '\\');

foreach ($patients as $row) {
    fputcsv($output, [
        $row['dcmt_patient_name'] ?? '',
        $row['dcmt_phone'] ?? '',
        $row['dcmt_email'] ?? '',
        !empty($row['last_appointment_at'])
            ? dcmt_format_date($row['last_appointment_at'], DCMT_DATETIME_FORMAT)
            : trans('patient', 'never_booked'),
    ], ',', '"', '\\');
}

fclose($output);
exit();
