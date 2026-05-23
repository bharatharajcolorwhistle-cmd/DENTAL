<?php
/**
 * Download Inventory Import Template
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/inventory_import_csv.php';

dcmt_require_admin_or_staff();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="inventory_import_template_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, [trans('inventory', 'import_csv_sheet_title')], ',', '"', '\\');

$headers = dcmt_inventory_import_template_headers();
fputcsv($output, $headers, ',', '"', '\\');

$sample_data = [
    [
        trans('inventory', 'import_csv_sample_name_1'),
        trans('inventory', 'import_csv_sample_brand_1'),
        trans('inventory', 'import_csv_sample_sku_1'),
        trans('inventory', 'import_csv_sample_desc_1'),
        trans('inventory', 'import_csv_sample_cat_1'),
        '50',
        '10',
        '5.99',
        'active',
        trans('inventory', 'import_csv_sample_supplier_1'),
        '2025-12-31',
        'admin',
    ],
    [
        trans('inventory', 'import_csv_sample_name_2'),
        trans('inventory', 'import_csv_sample_brand_2'),
        trans('inventory', 'import_csv_sample_sku_2'),
        trans('inventory', 'import_csv_sample_desc_2'),
        trans('inventory', 'import_csv_sample_cat_2'),
        '25',
        '5',
        '8.50',
        'active',
        trans('inventory', 'import_csv_sample_supplier_2'),
        '',
        'admin',
    ],
    [
        trans('inventory', 'import_csv_sample_name_3'),
        trans('inventory', 'import_csv_sample_brand_3'),
        trans('inventory', 'import_csv_sample_sku_3'),
        trans('inventory', 'import_csv_sample_desc_3'),
        trans('inventory', 'import_csv_sample_cat_3'),
        '15',
        '3',
        '25.00',
        'active',
        trans('inventory', 'import_csv_sample_supplier_3'),
        '2024-06-30',
        'admin',
    ],
];

foreach ($sample_data as $row) {
    fputcsv($output, $row, ',', '"', '\\');
}

fclose($output);
exit();
