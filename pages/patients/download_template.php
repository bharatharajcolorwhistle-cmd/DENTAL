<?php
/**
 * Download Patient Import Template
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/patient_import_csv.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="patient_import_template.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Title row (skipped on import); localized sheet title
fputcsv($output, [trans('patient', 'import_csv_sheet_title')], ',', '"', '\\');

$headers = dcmt_patient_import_standard_template_headers();
fputcsv($output, $headers, ',', '"', '\\');

$sample_row = [
    trans('patient', 'import_csv_sample_full_name'),
    trans('patient', 'import_csv_sample_first_name'),
    trans('patient', 'import_csv_sample_fathers_last_name'),
    trans('patient', 'import_csv_sample_mothers_last_name'),
    'male',
    '1990-05-20',
    '34',
    '175',
    '72',
    'juan@example.com',
    '+521234567890',
    trans('patient', 'import_csv_sample_address'),
    trans('patient', 'import_csv_sample_medications'),
    trans('patient', 'import_csv_sample_allergies'),
    trans('patient', 'import_csv_sample_emergency_name'),
    trans('patient', 'import_csv_sample_emergency_relation'),
    '+521234567891',
    trans('patient', 'import_csv_sample_notes'),
    'active',
];
fputcsv($output, $sample_row, ',', '"', '\\');

fclose($output);
exit();
