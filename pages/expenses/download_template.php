<?php
/**
 * Download Expense Import Template
 * Dental Clinic Management System
 */

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="expense_import_template_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers - Include all fields for better compatibility
$headers = [
    'title',
    'description',
    'category_name',
    'amount',
    'payment_method',
    'payment_status',
    'expense_date',
    'notes',
    'created_by'
];

fputcsv($output, $headers);

// Sample data rows - ensure all rows have the same number of fields
$sample_data = [
    [
        'Office Supplies',
        'Purchase of stationery and office supplies for the clinic',
        'Office Supplies',
        '150.00',
        'cash',
        'paid',
        '2024-01-15',
        'Purchased from local stationery store',
        'admin'
    ],
    [
        'Electricity Bill',
        'Monthly electricity bill payment',
        'Utilities',
        '450.00',
        'bank_transfer',
        'paid',
        '2024-01-16',
        'Paid via online banking',
        'admin'
    ],
    [
        'Equipment Maintenance',
        'Dental equipment maintenance and repair',
        'Equipment',
        '300.00',
        'card',
        'pending',
        '2024-01-17',
        'Scheduled maintenance for dental chair',
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
