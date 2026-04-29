<?php
/**
 * Export Expenses Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

dcmt_require_admin_or_staff();

// Get search and filter parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$category = isset($_GET['category']) ? dcmt_sanitize_input($_GET['category']) : '';
$payment_status = isset($_GET['payment_status']) ? dcmt_sanitize_input($_GET['payment_status']) : '';
$date_from = isset($_GET['date_from']) ? dcmt_sanitize_input($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? dcmt_sanitize_input($_GET['date_to']) : '';

// Parse date range if provided
if (!empty($_GET['date_range']) && strpos($_GET['date_range'], ' to ') !== false) {
    $dates = explode(' to ', $_GET['date_range']);
    if (count($dates) === 2) {
        $date_from = trim($dates[0]);
        $date_to = trim($dates[1]);
    }
}

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(e.dcmt_title LIKE ? OR e.dcmt_description LIKE ? OR e.dcmt_payment_method LIKE ? OR pm.dcmt_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    $where_conditions[] = "e.dcmt_category_id = ?";
    $params[] = $category;
}

if (!empty($payment_status)) {
    $where_conditions[] = "e.dcmt_payment_status = ?";
    $params[] = $payment_status;
}

if (!empty($date_from)) {
    $where_conditions[] = "e.dcmt_expense_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "e.dcmt_expense_date <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get expense records for export
try {
    $sql = "
        SELECT e.*, c.dcmt_name as category_name, u.dcmt_username as created_by_name, 
               pm.dcmt_name as payment_method_name,
               COALESCE(NULLIF(e.dcmt_payment_method, ''), pm.dcmt_name, 'cash') as payment_method,
               e.dcmt_payment_status
        FROM dcmt_expenses e
        LEFT JOIN dcmt_expense_categories c ON e.dcmt_category_id = c.dcmt_id
        LEFT JOIN dcmt_users u ON e.dcmt_created_by = u.dcmt_username
        LEFT JOIN dcmt_expense_payment_methods pm ON e.dcmt_payment_method_id = pm.dcmt_id
        $where_clause
        ORDER BY e.dcmt_expense_date DESC, e.dcmt_created_at DESC
    ";
    
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $expense_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log export activity
    dcmt_log_activity("Expense records exported to CSV - " . count($expense_records) . " records", "expense_exported");
    
} catch (PDOException $e) {
    dcmt_show_message(trans('expense', 'database_error'), "error");
    dcmt_redirect("index.php");
    exit();
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="expenses_' . date('Y-m-d_H-i-s') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers - Use database field names for compatibility with import
$headers = [
    'id',
    'title',
    'description',
    'category_id',
    'category_name',
    'amount',
    'payment_method_id',
    'payment_method',
    'payment_status',
    'expense_date',
    'notes',
    'created_by',
    'created_at',
    'updated_at'
];

fputcsv($output, $headers, ',', '"', '\\');

// CSV Data
foreach ($expense_records as $expense) {
    // Get payment_method - prefer from query calculation, fallback to fields, then default
    $payment_method = 'cash'; // Default
    if (!empty($expense['payment_method'])) {
        $payment_method = $expense['payment_method'];
    } elseif (!empty($expense['dcmt_payment_method'])) {
        $payment_method = $expense['dcmt_payment_method'];
    } elseif (!empty($expense['payment_method_name'])) {
        $payment_method = $expense['payment_method_name'];
    }
    $payment_method = strtolower(trim($payment_method));
    
    $payment_status = strtolower($expense['dcmt_payment_status'] ?? 'paid');
    
    $row = [
        $expense['dcmt_id'],
        ucfirst($expense['dcmt_title']),
        $expense['dcmt_description'] ?? '',
        $expense['dcmt_category_id'] ?? '',
        $expense['category_name'] ?? '',
        $expense['dcmt_amount'],
        $expense['dcmt_payment_method_id'] ?? '',
        strtolower($payment_method),
        $payment_status,
        $expense['dcmt_expense_date'],
        $expense['dcmt_notes'] ?? '',
        $expense['created_by_name'],
        $expense['dcmt_created_at'],
        $expense['dcmt_updated_at'] ?? ''
    ];
    
    fputcsv($output, $row, ',', '"', '\\');
}

// Close output stream
fclose($output);

// Exit to prevent any additional output
exit();
?>
