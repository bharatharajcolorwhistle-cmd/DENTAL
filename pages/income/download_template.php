<?php
/**
 * Download Income Import Template
 * Dental Clinic Management System
 */

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="income_import_template_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers - Include all fields for better compatibility
$headers = [
    'patient_name',
    'type',
    'description',
    'amount',
    'paid_amount',
    'pending_amount',
    'consultation_paid_amount',
    'product_paid_amount',
    'total_paid_amount',
    'total_pending_amount',
    'consultation_fee',
    'service_id',
    'service_amount',
    'service_paid_amount',
    'service_pending_amount',
    'product_amount',
    'product_pending_amount',
    'payment_mode',
    'payment_method',
    'payment_status',
    'doctor_name',
    'transaction_date',
    'created_by',
    'service_items',
    'product_items',
    'payment_details'
];

fputcsv($output, $headers, ',', '"', '\\');

// Sample data rows - ensure all rows have the same number of fields
$sample_data = [
    [
        'John Doe',
        'consultation',
        'Regular dental checkup and cleaning',
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
        'Dr. Smith',
        '2024-01-15',
        'admin',
        'Cleaning (Doctor: Dr. Smith) - Qty: 1 @ $150.00 = $150.00',
        '',
        'consultation|150.00|2024-01-15|Cash|admin|'
    ],
    [
        'Jane Smith',
        'product_sale',
        'Product sale example',
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
        'Dr. Smith',
        '2024-01-16',
        'admin',
        '',
        'Product Name (SKU: ACTUAL-SKU-HERE) - Qty: 2 @ $8.50 = $17.00 | Another Product (SKU: ANOTHER-SKU-HERE) - Qty: 1 @ $5.99 = $5.99',
        'product|75.50|2024-01-16|Card|admin|'
    ],
    [
        'Bob Wilson',
        'consultation',
        'Root canal treatment',
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
        'Dr. Johnson',
        '2024-01-17',
        'admin',
        'Root Canal (Doctor: Dr. Johnson) - Qty: 1 @ $200.00 = $200.00',
        '',
        'consultation|50.00|2024-01-17|Bank Transfer|admin| || consultation|50.00|2024-01-20|Bank Transfer|admin|'
    ]
];

// Add sample data
foreach ($sample_data as $row) {
    fputcsv($output, $row, ',', '"', '\\');
}

// Add note rows explaining the formats
$note_row_service = [
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    'NOTE: For service_items, use format: Service Name (Doctor: Doctor Name) - Qty: 1 @ $150.00 = $150.00 | Or without doctor: Service Name - Qty: 1 @ $150.00 = $150.00',
    '',
    ''
];
fputcsv($output, $note_row_service, ',', '"', '\\');

$note_row_product = [
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    'NOTE: For product_items, use actual SKUs from your inventory. Format: Product Name (SKU: ACTUAL-SKU) - Qty: 2 @ $8.50 = $17.00',
    ''
];
fputcsv($output, $note_row_product, ',', '"', '\\');

$note_row_payment = [
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    'NOTE: For payment_details, use format: type|amount|paid_on|payment_method|recorded_by|notes || type|amount|paid_on|payment_method|recorded_by|notes. Types: consultation, product, or general'
];
fputcsv($output, $note_row_payment, ',', '"', '\\');

// Close output stream
fclose($output);

// Exit to prevent any additional output
exit();
?>
