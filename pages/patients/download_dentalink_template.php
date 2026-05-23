<?php
/**
 * Download Dentalink-format patient import template (localized column headers).
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
header('Content-Disposition: attachment; filename="patient_import_dentalink_template.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, [trans('patient', 'import_dentalink_sheet_title')], ',', '"', '\\');

$headers = [
    trans('patient', 'import_dentalink_hdr_patient_num'),
    trans('patient', 'import_dentalink_hdr_first_name'),
    trans('patient', 'import_dentalink_hdr_surnames'),
    trans('patient', 'import_dentalink_hdr_birth_date'),
    trans('patient', 'import_dentalink_hdr_age'),
    trans('patient', 'import_dentalink_hdr_phone'),
    trans('patient', 'import_dentalink_hdr_mobile'),
    trans('patient', 'import_dentalink_hdr_city'),
    trans('patient', 'import_dentalink_hdr_district'),
    trans('patient', 'import_dentalink_hdr_address_line'),
    trans('patient', 'import_dentalink_hdr_email'),
    trans('patient', 'import_dentalink_hdr_sex'),
    trans('patient', 'import_dentalink_hdr_guardian'),
];
fputcsv($output, $headers, ',', '"', '\\');

$sample_row = [
    '100',
    trans('patient', 'import_dentalink_sample_first_name'),
    trans('patient', 'import_dentalink_sample_surnames'),
    '1995-03-15',
    '31',
    '9711234567',
    '',
    trans('patient', 'import_dentalink_sample_city'),
    trans('patient', 'import_dentalink_sample_district'),
    trans('patient', 'import_dentalink_sample_address'),
    'maria@example.com',
    'F',
    trans('patient', 'import_dentalink_sample_guardian'),
];
fputcsv($output, $sample_row, ',', '"', '\\');

fclose($output);
exit();
