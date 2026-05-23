<?php
/**
 * Download Expense Import Template
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/expense_import_csv.php';

dcmt_require_admin_or_staff();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="expense_import_template_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, [trans('expense', 'import_csv_sheet_title')], ',', '"', '\\');

$headers = dcmt_expense_import_template_headers();
fputcsv($output, $headers, ',', '"', '\\');

$sample_data = [
    [
        trans('expense', 'import_csv_sample_title_1'),
        trans('expense', 'import_csv_sample_desc_1'),
        trans('expense', 'import_csv_sample_cat_1'),
        '150.00',
        'cash',
        'paid',
        '2024-01-15',
        trans('expense', 'import_csv_sample_notes_1'),
        'admin',
    ],
    [
        trans('expense', 'import_csv_sample_title_2'),
        trans('expense', 'import_csv_sample_desc_2'),
        trans('expense', 'import_csv_sample_cat_2'),
        '450.00',
        'bank_transfer',
        'paid',
        '2024-01-16',
        trans('expense', 'import_csv_sample_notes_2'),
        'admin',
    ],
    [
        trans('expense', 'import_csv_sample_title_3'),
        trans('expense', 'import_csv_sample_desc_3'),
        trans('expense', 'import_csv_sample_cat_3'),
        '300.00',
        'card',
        'pending',
        '2024-01-17',
        trans('expense', 'import_csv_sample_notes_3'),
        'admin',
    ],
];

foreach ($sample_data as $row) {
    fputcsv($output, $row, ',', '"', '\\');
}

fclose($output);
exit();
