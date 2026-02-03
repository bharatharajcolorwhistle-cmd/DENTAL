<?php
/**
 * Export Inventory Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Check authentication
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

// Get search and filter parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$category = isset($_GET['category']) ? dcmt_sanitize_input($_GET['category']) : '';
$status = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';
$stock_level = isset($_GET['stock_level']) ? dcmt_sanitize_input($_GET['stock_level']) : '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(i.dcmt_name LIKE ? OR i.dcmt_description LIKE ? OR i.dcmt_sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    $where_conditions[] = "i.dcmt_category_id = ?";
    $params[] = $category;
}

if (!empty($status)) {
    $where_conditions[] = "i.dcmt_status = ?";
    $params[] = $status;
}

if (!empty($stock_level)) {
    switch ($stock_level) {
        case 'low':
            $where_conditions[] = "i.dcmt_quantity <= i.dcmt_min_quantity";
            break;
        case 'out':
            $where_conditions[] = "i.dcmt_quantity = 0";
            break;
        case 'available':
            $where_conditions[] = "i.dcmt_quantity > 0";
            break;
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get inventory records for export
try {
    $sql = "
        SELECT i.*, c.dcmt_name as category_name, u.dcmt_full_name as created_by_name
        FROM dcmt_inventory i
        LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id
        LEFT JOIN dcmt_users u ON i.dcmt_created_by = u.dcmt_username
        $where_clause
        ORDER BY i.dcmt_name ASC
    ";
    
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $inventory_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log export activity
    dcmt_log_activity("Inventory records exported to CSV - " . count($inventory_records) . " records", "inventory_exported");
    
} catch (PDOException $e) {
    dcmt_show_message(trans('inventory', 'database_error'), "error");
    dcmt_redirect("index.php");
    exit();
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="inventory_' . date('Y-m-d_H-i-s') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers - Use database field names for compatibility with import
$headers = [
    'id',
    'name',
    'sku',
    'description',
    'category_name',
    'quantity',
    'min_quantity',
    'price',
    'status',
    'supplier',
    'expiry_date',
    'created_by',
    'created_at',
    'updated_at'
];

fputcsv($output, $headers);

// CSV Data
foreach ($inventory_records as $item) {
    // Use dcmt_status directly from database for consistency
    $status = strtolower($item['dcmt_status'] ?? 'active');
    
    $row = [
        $item['dcmt_id'],
        ucfirst($item['dcmt_name']),
        strtoupper($item['dcmt_sku']),
        $item['dcmt_description'] ?? '',
        $item['category_name'] ?? '',
        $item['dcmt_quantity'],
        $item['dcmt_min_quantity'],
        $item['dcmt_price'],
        $status,
        $item['dcmt_supplier'] ?? '',
        $item['dcmt_expiry_date'] ?? '',
        $item['created_by_name'],
        $item['dcmt_created_at'],
        $item['dcmt_updated_at'] ?? ''
    ];
    
    fputcsv($output, $row);
}

// Close output stream
fclose($output);

// Exit to prevent any additional output
exit();
?>
