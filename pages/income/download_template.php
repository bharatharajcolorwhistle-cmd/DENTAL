<?php
/**
 * Download Income Import Template
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/income_import_csv.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="income_import_template_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, [trans('income', 'import_csv_sheet_title')], ',', '"', '\\');

$headers = dcmt_income_import_template_headers();
$colCount = count($headers);
fputcsv($output, $headers, ',', '"', '\\');

$template_order = dcmt_income_import_template_field_order();
$sample_data = [
    [
        trans('income', 'import_csv_sample_patient_1'),
        'consultation',
        trans('income', 'import_csv_sample_desc_1'),
        '150.00',
        '150.00',
        '0.00',
        '150.00',
        '0.00',
        '150.00',
        '0.00',
        '150.00',
        '',
        '0.00',
        '0.00',
        '0.00',
        '0.00',
        '0.00',
        'cash',
        'cash',
        'completed',
        trans('income', 'import_csv_sample_doctor_1'),
        '2024-01-15',
        'admin',
        trans('income', 'import_csv_sample_service_items_1'),
        '',
        'consultation|150.00|2024-01-15|Cash|admin|',
    ],
    [
        trans('income', 'import_csv_sample_patient_2'),
        'product_sale',
        trans('income', 'import_csv_sample_desc_2'),
        '75.50',
        '75.50',
        '0.00',
        '0.00',
        '75.50',
        '75.50',
        '0.00',
        '',
        '',
        '0.00',
        '0.00',
        '0.00',
        '75.50',
        '0.00',
        'card',
        'card',
        'completed',
        trans('income', 'import_csv_sample_doctor_1'),
        '2024-01-16',
        'admin',
        '',
        trans('income', 'import_csv_sample_product_items_2'),
        'product|75.50|2024-01-16|Card|admin|',
    ],
    [
        trans('income', 'import_csv_sample_patient_3'),
        'consultation',
        trans('income', 'import_csv_sample_desc_3'),
        '200.00',
        '100.00',
        '100.00',
        '100.00',
        '0.00',
        '100.00',
        '100.00',
        '200.00',
        '',
        '0.00',
        '0.00',
        '0.00',
        '0.00',
        '0.00',
        'bank_transfer',
        'bank_transfer',
        'pending',
        trans('income', 'import_csv_sample_doctor_2'),
        '2024-01-17',
        'admin',
        trans('income', 'import_csv_sample_service_items_3'),
        '',
        'consultation|50.00|2024-01-17|Bank Transfer|admin| || consultation|50.00|2024-01-20|Bank Transfer|admin|',
    ],
];

foreach ($sample_data as $row) {
    fputcsv($output, $row, ',', '"', '\\');
}

$note_row = static function (string $text, string $field) use ($colCount, $template_order): array {
    $r = array_fill(0, $colCount, '');
    $i = array_search($field, $template_order, true);
    if ($i !== false) {
        $r[$i] = $text;
    }
    return $r;
};

fputcsv($output, $note_row(trans('income', 'import_csv_note_service'), 'service_items'), ',', '"', '\\');
fputcsv($output, $note_row(trans('income', 'import_csv_note_product'), 'product_items'), ',', '"', '\\');
fputcsv($output, $note_row(trans('income', 'import_csv_note_payment'), 'payment_details'), ',', '"', '\\');

fclose($output);
exit();
