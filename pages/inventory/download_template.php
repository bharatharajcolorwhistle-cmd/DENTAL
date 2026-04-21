<?php
/**
 * Download Inventory Import Template
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

dcmt_require_admin_or_staff();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="inventory_import_template_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers - Include all fields for better compatibility
$headers = [
    'name',
    'brand',
    'sku',
    'description',
    'category_name',
    'quantity',
    'min_quantity',
    'price',
    'status',
    'supplier',
    'expiry_date',
    'created_by'
];

fputcsv($output, $headers);

// Sample data rows - ensure all rows have the same number of fields
$sample_data = [
    [
        'Dental Floss',
        'OralCare Pro',
        'FLOSS-001',
        'High-quality dental floss for oral hygiene',
        'Oral Care',
        '50',
        '10',
        '5.99',
        'active',
        'Dental Supplies Co.',
        '2025-12-31',
        'admin'
    ],
    [
        'Toothbrush',
        'SmileBrush',
        'BRUSH-001',
        'Soft-bristled toothbrush for daily use',
        'Oral Care',
        '25',
        '5',
        '8.50',
        'active',
        'Dental Supplies Co.',
        '',
        'admin'
    ],
    [
        'Anesthetic Solution',
        'ANEST-001',
        'Local anesthetic for dental procedures',
        'Medical Supplies',
        '15',
        '3',
        '25.00',
        'active',
        'Medical Equipment Inc.',
        '2024-06-30',
        'admin'
    ]
];

// Add sample data
foreach ($sample_data as $row) {
    fputcsv($output, $row);
}

// Close output stream
fclose($output);

// Exit to prevent any additional output
exit();
?>
