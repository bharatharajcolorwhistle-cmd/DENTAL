<?php
/**
 * Export Income Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

if (!function_exists('dcmt_format_quantity_display')) {
    function dcmt_format_quantity_display($quantity) {
        if ($quantity === null || $quantity === '') {
            return '';
        }
        if (!is_numeric($quantity)) {
            return $quantity;
        }
        $formatted = rtrim(rtrim(number_format((float)$quantity, 2, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}

// Check authentication
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

// Get search and filter parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? dcmt_sanitize_input($_GET['type']) : '';
$doctor_filter = isset($_GET['doctor']) ? dcmt_sanitize_input($_GET['doctor']) : '';
$status_filter = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';
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
    $where_conditions[] = "(i.dcmt_patient_name LIKE ? OR i.dcmt_description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($type_filter)) {
    $where_conditions[] = "i.dcmt_type = ?";
    $params[] = $type_filter;
}

if (!empty($doctor_filter)) {
    $where_conditions[] = "i.dcmt_user_id = ?";
    $params[] = $doctor_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "ps.dcmt_name = ?";
    $params[] = $status_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "i.dcmt_transaction_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "i.dcmt_transaction_date <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get income records for export
try {
    $sql = "
        SELECT i.*, u_doctor.dcmt_full_name as doctor_name, u.dcmt_username as created_by_name, 
               pm.dcmt_name as payment_method_name, ps.dcmt_name as payment_status_name,
               i.dcmt_payment_mode, i.dcmt_payment_status
        FROM dcmt_income i
        LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
        LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
        LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
        LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
        $where_clause
        ORDER BY i.dcmt_transaction_date DESC, i.dcmt_created_at DESC
    ";
    
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $income_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log export activity
    dcmt_log_activity("Income records exported to CSV - " . count($income_records) . " records", "income_exported");
    
} catch (PDOException $e) {
    dcmt_show_message(trans('income', 'database_error'), "error");
    dcmt_redirect("index.php");
    exit();
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="income_' . date('Y-m-d_H-i-s') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers - Use database field names for compatibility with import
$headers = [
    'id',
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
    'payment_method_id',
    'payment_method',
    'payment_status_id',
    'payment_status',
    'doctor_id',
    'doctor_name',
    'transaction_date',
    'created_by',
    'created_at',
    'updated_at',
    'service_items',
    'product_items',
    'payment_details'
];

fputcsv($output, $headers, ',', '"', '\\');

// CSV Data
foreach ($income_records as $income) {
    // Get service items from breakdown table
    $service_items_data = '';
    $stmt = $dcmt_pdo->prepare("
        SELECT ib.*, s.dcmt_name as service_name, u_doctor.dcmt_full_name as doctor_name
        FROM dcmt_income_breakdown ib
        LEFT JOIN dcmt_services s ON ib.dcmt_reference_id = s.dcmt_id AND ib.dcmt_line_type = 'service'
        LEFT JOIN dcmt_users u_doctor ON ib.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
        WHERE ib.dcmt_id = ? AND ib.dcmt_line_type = 'service'
        ORDER BY ib.dcmt_line_no
    ");
    $stmt->execute([$income['dcmt_id']]);
    $service_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($service_items)) {
        $items = [];
        foreach ($service_items as $item) {
            $doctor_name = $item['doctor_name'] ? ' (Doctor: ' . $item['doctor_name'] . ')' : '';
            $items[] = sprintf(
                '%s%s - Qty: %s @ $%.2f = $%.2f',
                $item['service_name'] ?? $item['dcmt_label'],
                $doctor_name,
                dcmt_format_quantity_display($item['dcmt_quantity']),
                $item['dcmt_unit_price'],
                $item['dcmt_line_total']
            );
        }
        $service_items_data = implode(' | ', $items);
    }
    
    // Get product items from breakdown table
    $product_items_data = '';
    $stmt = $dcmt_pdo->prepare("
        SELECT ib.*, inv.dcmt_name as product_name, inv.dcmt_sku
        FROM dcmt_income_breakdown ib
        LEFT JOIN dcmt_inventory inv ON ib.dcmt_inventory_id = inv.dcmt_id
        WHERE ib.dcmt_id = ? AND ib.dcmt_line_type = 'product'
        ORDER BY ib.dcmt_line_no
    ");
    $stmt->execute([$income['dcmt_id']]);
    $product_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($product_items)) {
        $items = [];
        foreach ($product_items as $item) {
            $items[] = sprintf(
                '%s (SKU: %s) - Qty: %s @ $%.2f = $%.2f',
                $item['product_name'],
                $item['dcmt_sku'],
                dcmt_format_quantity_display($item['dcmt_quantity']),
                $item['dcmt_unit_price'],
                $item['dcmt_line_total']
            );
        }
        $product_items_data = implode(' | ', $items);
    }
    
    // Use payment_status_name from lookup table for better import compatibility
    // Fall back to dcmt_payment_status enum if name not available
    $payment_status_export = $income['payment_status_name'] ?? $income['dcmt_payment_status'] ?? 'Pending';
    
    // Get payment history/details for this income record
    $payment_details_data = '';
    $stmt = $dcmt_pdo->prepare("
        SELECT iph.*, pm.dcmt_name as payment_method_name
        FROM dcmt_income_payment_history iph
        LEFT JOIN dcmt_income_payment_methods pm ON JSON_EXTRACT(iph.dcmt_notes, '$.payment_method_id') = pm.dcmt_id
        WHERE iph.dcmt_income_id = ?
        ORDER BY iph.dcmt_paid_on ASC, iph.dcmt_id ASC
    ");
    $stmt->execute([$income['dcmt_id']]);
    $payment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($payment_history)) {
        $payment_entries = [];
        foreach ($payment_history as $payment) {
            // Extract payment method from notes if not found in join
            $payment_method_name = $payment['payment_method_name'] ?? '';
            if (empty($payment_method_name) && !empty($payment['dcmt_notes'])) {
                $notes_data = json_decode($payment['dcmt_notes'], true);
                if (is_array($notes_data) && isset($notes_data['payment_method_id'])) {
                    $method_id = (int)$notes_data['payment_method_id'];
                    $stmt_method = $dcmt_pdo->prepare("SELECT dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_id = ?");
                    $stmt_method->execute([$method_id]);
                    $method_record = $stmt_method->fetch();
                    if ($method_record) {
                        $payment_method_name = $method_record['dcmt_name'];
                    }
                }
            }
            
            // Format: type|amount|paid_on|payment_method|recorded_by|notes
            $notes_text = '';
            if (!empty($payment['dcmt_notes'])) {
                $notes_data = json_decode($payment['dcmt_notes'], true);
                if (is_array($notes_data)) {
                    // Remove payment_method_id from notes as it's already in payment_method field
                    unset($notes_data['payment_method_id']);
                    if (!empty($notes_data)) {
                        $notes_text = json_encode($notes_data, JSON_UNESCAPED_UNICODE);
                    }
                } else {
                    $notes_text = $payment['dcmt_notes'];
                }
            }
            
            $payment_entries[] = sprintf(
                '%s|%.2f|%s|%s|%s|%s',
                $payment['dcmt_payment_type'],
                $payment['dcmt_amount'],
                $payment['dcmt_paid_on'],
                $payment_method_name,
                $payment['dcmt_recorded_by'],
                $notes_text
            );
        }
        $payment_details_data = implode(' || ', $payment_entries);
    }
    
    $row = [
        $income['dcmt_id'],
        ucfirst($income['dcmt_patient_name']),
        $income['dcmt_type'],
        $income['dcmt_description'] ?? '',
        $income['dcmt_amount'],
        $income['dcmt_paid_amount'] ?? 0,
        $income['dcmt_pending_amount'] ?? 0,
        $income['dcmt_consultation_paid_amount'] ?? 0,
        $income['dcmt_product_paid_amount'] ?? 0,
        $income['dcmt_total_paid_amount'] ?? 0,
        $income['dcmt_total_pending_amount'] ?? 0,
        $income['dcmt_consultation_fee'] ?? '',
        $income['dcmt_service_id'] ?? '',
        $income['dcmt_service_amount'] ?? 0,
        $income['dcmt_service_paid_amount'] ?? 0,
        $income['dcmt_service_pending_amount'] ?? 0,
        $income['dcmt_product_amount'] ?? 0,
        $income['dcmt_product_pending_amount'] ?? 0,
        strtolower($income['dcmt_payment_mode'] ?? 'cash'),
        $income['dcmt_payment_method_id'] ?? '',
        $income['payment_method_name'] ?? '',
        $income['dcmt_payment_status_id'] ?? '',
        $payment_status_export,
        $income['dcmt_user_id'] ?? '',
        $income['doctor_name'] ? ucfirst($income['doctor_name']) : '',
        $income['dcmt_transaction_date'],
        $income['created_by_name'],
        $income['dcmt_created_at'],
        $income['dcmt_updated_at'] ?? '',
        $service_items_data,
        $product_items_data,
        $payment_details_data
    ];
    
    fputcsv($output, $row, ',', '"', '\\');
}

// Close output stream
fclose($output);

// Exit to prevent any additional output
exit();
?>
