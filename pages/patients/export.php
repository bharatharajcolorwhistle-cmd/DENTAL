<?php
/**
 * Export Patients Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$status = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';

$where_conditions = [];
$params = [];

if ($search !== '') {
    $where_conditions[] = "(dcmt_patient_name LIKE ? OR dcmt_phone LIKE ? OR dcmt_email LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status !== '') {
    $where_conditions[] = "dcmt_status = ?";
    $params[] = $status;
}

$where_clause = !empty($where_conditions) ? ('WHERE ' . implode(' AND ', $where_conditions)) : '';

try {
    $sql = "
        SELECT *
        FROM dcmt_patients
        $where_clause
        ORDER BY dcmt_created_at DESC
    ";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    dcmt_log_activity("Patient records exported to CSV - " . count($patients) . " records", "patient_exported");
} catch (PDOException $e) {
    dcmt_show_message(trans('patient', 'database_error'), 'error');
    dcmt_redirect('index.php');
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="patients_' . date('Y-m-d_H-i-s') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

$headers = [
    'id',
    'patient_name',
    'first_name',
    'fathers_last_name',
    'mothers_last_name',
    'gender',
    'date_of_birth',
    'age',
    'height_cm',
    'weight_kg',
    'email',
    'phone',
    'address',
    'medications',
    'allergies',
    'emergency_contact_name',
    'emergency_contact_relation',
    'emergency_contact_phone',
    'notes',
    'status',
    'created_by',
    'created_at',
    'updated_at'
];
fputcsv($output, $headers);

foreach ($patients as $patient) {
    $row = [
        $patient['dcmt_id'] ?? '',
        $patient['dcmt_patient_name'] ?? '',
        $patient['dcmt_first_name'] ?? '',
        $patient['dcmt_fathers_last_name'] ?? '',
        $patient['dcmt_mothers_last_name'] ?? '',
        strtolower((string)($patient['dcmt_gender'] ?? 'other')),
        $patient['dcmt_date_of_birth'] ?? '',
        $patient['dcmt_age'] ?? '',
        $patient['dcmt_height_cm'] ?? '',
        $patient['dcmt_weight_kg'] ?? '',
        $patient['dcmt_email'] ?? '',
        $patient['dcmt_phone'] ?? '',
        $patient['dcmt_address'] ?? '',
        $patient['dcmt_medications'] ?? '',
        $patient['dcmt_allergies'] ?? '',
        $patient['dcmt_emergency_contact_name'] ?? '',
        $patient['dcmt_emergency_contact_relation'] ?? '',
        $patient['dcmt_emergency_contact_phone'] ?? '',
        $patient['dcmt_notes'] ?? '',
        strtolower((string)($patient['dcmt_status'] ?? 'active')),
        $patient['dcmt_created_by'] ?? '',
        $patient['dcmt_created_at'] ?? '',
        $patient['dcmt_updated_at'] ?? ''
    ];
    fputcsv($output, $row);
}

fclose($output);
exit();

