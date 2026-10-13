<?php
/**
 * Export Patients with Advance (unused balances) to CSV.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_advance_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_patient_advance_require_access();
dcmt_patient_advance_ensure_tables($dcmt_pdo);

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';

$where = ["a.dcmt_status <> 'refunded'"];
$params = [];
if ($search !== '') {
    $where[] = '(p.dcmt_patient_name LIKE ? OR p.dcmt_phone LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$patients = [];

try {
    $sql = "
        SELECT
            p.dcmt_id,
            p.dcmt_patient_name,
            p.dcmt_phone,
            SUM(a.dcmt_amount) AS advance_amount,
            SUM(a.dcmt_remaining_amount) AS remaining_amount,
            MAX(a.dcmt_received_on) AS last_received_on
        FROM dcmt_patient_advances a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        {$where_sql}
        GROUP BY p.dcmt_id, p.dcmt_patient_name, p.dcmt_phone
        HAVING SUM(a.dcmt_remaining_amount) > 0.009
        ORDER BY remaining_amount DESC, p.dcmt_patient_name ASC
    ";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    dcmt_log_activity('Patients with advance exported to CSV - ' . count($patients) . ' records', 'patient_advance_exported');
} catch (PDOException $e) {
    error_log('Patient advances export error: ' . $e->getMessage());
    dcmt_show_message(trans('patient_advance', 'database_error'), 'error');
    dcmt_redirect('index.php');
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="patients_with_advance_' . date('Y-m-d_H-i-s') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

$headers = [
    trans('patient_advance', 'patient'),
    trans('patient_advance', 'phone'),
    trans('patient_advance', 'advance_amount'),
    trans('patient_advance', 'remaining_balance'),
    trans('patient_advance', 'last_received'),
];
fputcsv($output, $headers, ',', '"', '\\');

foreach ($patients as $row) {
    fputcsv($output, [
        $row['dcmt_patient_name'] ?? '',
        $row['dcmt_phone'] ?? '',
        number_format((float) ($row['advance_amount'] ?? 0), 2, '.', ''),
        number_format((float) ($row['remaining_amount'] ?? 0), 2, '.', ''),
        !empty($row['last_received_on']) ? dcmt_format_date($row['last_received_on']) : '',
    ], ',', '"', '\\');
}

fclose($output);
exit();
