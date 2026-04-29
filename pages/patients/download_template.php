<?php
/**
 * Download Patient Import Template
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="patient_import_template.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

$headers = [
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
    'status'
];
fputcsv($output, $headers, ',', '"', '\\');

$sample_row = [
    'Juan Perez Lopez',
    'Juan',
    'Perez',
    'Lopez',
    'male',
    '1990-05-20',
    '34',
    '175',
    '72',
    'juan@example.com',
    '+521234567890',
    'Street 123, City',
    'None',
    'Penicillin',
    'Maria Perez',
    'Sister',
    '+521234567891',
    'Sample patient note',
    'active'
];
fputcsv($output, $sample_row, ',', '"', '\\');

fclose($output);
exit();

