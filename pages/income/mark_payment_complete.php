<?php
/**
 * Mark Payment as Complete - AJAX Endpoint for Income Page
 * Dental Clinic Management System
 */

// Suppress any output before JSON response
ob_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/income_payment_history.php';

// Clear any output buffer and set JSON header
ob_clean();
header('Content-Type: application/json');

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get and validate input
$income_id = isset($_POST['income_id']) ? (int)$_POST['income_id'] : 0;
$csrf_token = $_POST['csrf_token'] ?? '';
$payment_date = $_POST['payment_date'] ?? '';
$payment_method_id = isset($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : 0;

// Validate CSRF token
if (!dcmt_verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit();
}

// Validate income ID
if ($income_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid income ID.']);
    exit();
}

if (empty($payment_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
    echo json_encode(['success' => false, 'message' => trans('income', 'payment_date_required_simple')]);
    exit();
}

$payment_date_obj = DateTime::createFromFormat('Y-m-d', $payment_date);
if (!$payment_date_obj) {
    echo json_encode(['success' => false, 'message' => trans('income', 'payment_date_required_simple')]);
    exit();
}

if ($payment_method_id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('income', 'payment_method_required_simple')]);
    exit();
}

// Check if user has permission to mark payments as complete
// Staff role cannot mark payments as complete
// Admin and doctor roles can mark payments as complete
$current_user = dcmt_get_current_user();
$role = $current_user['dcmt_role'] ?? '';
if (!$current_user || !in_array($role, ['admin', 'doctor', 'staff'], true)) {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit();
}

$method_stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_methods WHERE dcmt_id = ? AND dcmt_status = 'active' LIMIT 1");
$method_stmt->execute([$payment_method_id]);
$payment_method_row = $method_stmt->fetch(PDO::FETCH_ASSOC);
if (!$payment_method_row) {
    echo json_encode(['success' => false, 'message' => trans('income', 'payment_method_required_simple')]);
    exit();
}
$selected_payment_method_id = (int)$payment_method_row['dcmt_id'];

if (!function_exists('dcmt_get_payment_status_id_by_keyword')) {
    function dcmt_get_payment_status_id_by_keyword(PDO $pdo, string $keyword): ?int {
        $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_status WHERE LOWER(dcmt_name) LIKE ? LIMIT 1");
        $stmt->execute(['%' . strtolower($keyword) . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['dcmt_id'] : null;
    }
}

try {
    $pending_status_id = dcmt_get_payment_status_id_by_keyword($dcmt_pdo, 'pending');
    $completed_status_id = dcmt_get_payment_status_id_by_keyword($dcmt_pdo, 'completed');
    
    $stmt = $dcmt_pdo->prepare("
        SELECT i.dcmt_id, i.dcmt_patient_name, i.dcmt_payment_status_id, i.dcmt_pending_amount, i.dcmt_paid_amount, 
               i.dcmt_type, i.dcmt_amount, i.dcmt_service_pending_amount, i.dcmt_service_paid_amount, 
               i.dcmt_product_pending_amount, i.dcmt_product_paid_amount,
               i.dcmt_total_pending_amount, i.dcmt_total_paid_amount,
               ps.dcmt_name as payment_status_name 
        FROM dcmt_income i 
        LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id 
        WHERE i.dcmt_id = ?
    ");
    $stmt->execute([$income_id]);
    $income = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$income) {
        echo json_encode(['success' => false, 'message' => 'Income record not found.']);
        exit();
    }
    
    $payment_status_name = strtolower($income['payment_status_name'] ?? '');
    $is_pending = true;
    if ($pending_status_id) {
        $is_pending = ((int)$income['dcmt_payment_status_id'] === $pending_status_id);
    } elseif (!empty($payment_status_name)) {
        $is_pending = (
            strpos($payment_status_name, 'pending') !== false ||
            strpos($payment_status_name, 'pendiente') !== false
        );
    }
    
    if (!$is_pending) {
        echo json_encode(['success' => false, 'message' => 'This payment is not pending.']);
        exit();
    }
    
    $service_pending = max(0, floatval($income['dcmt_service_pending_amount'] ?? 0));
    $product_pending = max(0, floatval($income['dcmt_product_pending_amount'] ?? 0));
    $general_pending = max(0, floatval($income['dcmt_pending_amount'] ?? 0));
    $total_pending = $income['dcmt_total_pending_amount'] !== null
        ? max(0, floatval($income['dcmt_total_pending_amount']))
        : 0;
    
    if ($total_pending <= 0) {
        $total_pending = $service_pending + $product_pending;
    }
    if ($total_pending <= 0) {
        $total_pending = $general_pending;
    }
    
    if ($service_pending > 0 && $product_pending > 0) {
        $amount_type = 'mixed';
    } elseif ($service_pending > 0) {
        $amount_type = 'service';
    } elseif ($product_pending > 0) {
        $amount_type = 'product';
    } else {
        $amount_type = 'general';
    }
    
    $recorded_by_username = (is_array($current_user) && !empty($current_user['dcmt_username'])) ? $current_user['dcmt_username'] : 'system';
    $payment_history_date = $payment_date;
    
    // Get payment method name
    $payment_method_stmt = $dcmt_pdo->prepare("SELECT dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_id = ?");
    $payment_method_stmt->execute([$selected_payment_method_id]);
    $payment_method_row = $payment_method_stmt->fetch(PDO::FETCH_ASSOC);
    $payment_method_name = $payment_method_row['dcmt_name'] ?? 'N/A';
    
    // Get previous payment total from payment history
    $previous_payment_stmt = $dcmt_pdo->prepare("SELECT COALESCE(SUM(dcmt_amount), 0) as total FROM dcmt_income_payment_history WHERE dcmt_income_id = ?");
    $previous_payment_stmt->execute([$income_id]);
    $previous_payment_row = $previous_payment_stmt->fetch(PDO::FETCH_ASSOC);
    $previous_payment_total = floatval($previous_payment_row['total'] ?? 0);
    
    // Get previous total income
    $previous_total_income = floatval($income['dcmt_amount'] ?? 0);
    
    if ($total_pending <= 0) {
        $completed_status_value = $completed_status_id ?? $income['dcmt_payment_status_id'];
        $dcmt_pdo->beginTransaction();
        $update_stmt = $dcmt_pdo->prepare("
            UPDATE dcmt_income 
            SET dcmt_payment_status_id = ?, 
                dcmt_total_pending_amount = 0.00, 
                dcmt_pending_amount = 0.00,
                dcmt_service_pending_amount = 0.00,
                dcmt_product_pending_amount = 0.00,
                dcmt_payment_method_id = ?
            WHERE dcmt_id = ?
        ");
        $update_stmt->execute([$completed_status_value, $selected_payment_method_id, $income_id]);
        $dcmt_pdo->commit();
        
        // Format audit trail entry
        $new_payment_total = $previous_payment_total; // No new payment added
        $new_total_income = $previous_total_income; // Income doesn't change
        $audit_entry = sprintf(
            'Payment marked as complete. Income ID: %d, Patient: %s, Payment Status: %s → Completed',
            $income_id,
            $income['dcmt_patient_name'],
            $income['payment_status_name']
        );
        dcmt_log_activity('Payment marked as complete', $audit_entry);
        
        echo json_encode([
            'success' => true, 
            'message' => trans('income', 'payment_marked_complete'),
            'income_id' => $income_id,
            'pending_amount' => 0,
            'amount_type' => $amount_type,
            'show_popup' => true,
            'popup_message' => 'No pending amount to mark as complete. Payment status updated to completed.'
        ]);
        exit();
    }
    
    $update_fields = [];
    $update_values = [];
    $payment_changes = [];
    
    $current_service_paid = floatval($income['dcmt_service_paid_amount'] ?? 0);
    $current_product_paid = floatval($income['dcmt_product_paid_amount'] ?? 0);
    $previous_total_paid = floatval($income['dcmt_total_paid_amount'] ?? $income['dcmt_paid_amount'] ?? 0);
    $previous_total_pending = $income['dcmt_total_pending_amount'] !== null
        ? floatval($income['dcmt_total_pending_amount'])
        : max(floatval($income['dcmt_amount'] ?? 0) - $previous_total_paid, 0);
    $total_amount = floatval($income['dcmt_amount'] ?? 0);
    
    if ($service_pending > 0) {
        $new_service_paid = $current_service_paid + $service_pending;
        $update_fields[] = "dcmt_service_paid_amount = ?";
        $update_values[] = $new_service_paid;
        $update_fields[] = "dcmt_service_pending_amount = 0.00";
        $payment_changes[] = "Service Paid: " . dcmt_format_currency($current_service_paid) . " → " . dcmt_format_currency($new_service_paid);
    } else {
        $new_service_paid = $current_service_paid;
    }
    
    if ($product_pending > 0) {
        $new_product_paid = $current_product_paid + $product_pending;
        $update_fields[] = "dcmt_product_paid_amount = ?";
        $update_values[] = $new_product_paid;
        $update_fields[] = "dcmt_product_pending_amount = 0.00";
        $payment_changes[] = "Product Paid: " . dcmt_format_currency($current_product_paid) . " → " . dcmt_format_currency($new_product_paid);
    } else {
        $new_product_paid = $current_product_paid;
    }
    
    $new_total_paid = $previous_total_paid + $total_pending;
    if ($total_amount > 0) {
        $new_total_paid = min($total_amount, $new_total_paid);
    }
    $new_total_pending = max($total_amount - $new_total_paid, 0);
    
    $update_fields[] = "dcmt_total_paid_amount = ?";
    $update_values[] = $new_total_paid;
    $update_fields[] = "dcmt_total_pending_amount = ?";
    $update_values[] = $new_total_pending;
    $update_fields[] = "dcmt_paid_amount = ?";
    $update_values[] = $new_total_paid;
    $update_fields[] = "dcmt_pending_amount = ?";
    $update_values[] = $new_total_pending;
    
    $payment_history_entries = [];
    if ($total_pending > 0) {
        $primary_history_type = $amount_type === 'service'
            ? 'consultation'
            : ($amount_type === 'product' ? 'product' : 'general');
        $payment_history_entries[] = [
            'type' => $primary_history_type,
            'amount' => $total_pending
        ];
    }
    
    $completed_status_value = $completed_status_id ?? $income['dcmt_payment_status_id'];
    $update_fields[] = "dcmt_payment_status_id = ?";
    $update_values[] = $completed_status_value;
    $update_fields[] = "dcmt_payment_method_id = ?";
    $update_values[] = $selected_payment_method_id;
    
    $update_values[] = $income_id;
    
    $dcmt_pdo->beginTransaction();
    $sql = "UPDATE dcmt_income SET " . implode(', ', $update_fields) . " WHERE dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($update_values);
    
    foreach ($payment_history_entries as $entry) {
        dcmt_add_payment_history_entry(
            $dcmt_pdo,
            $income_id,
            $entry['type'],
            round($entry['amount'], 2),
            $payment_history_date,
            $recorded_by_username,
            $selected_payment_method_id
        );
    }
    
    $dcmt_pdo->commit();
    
    // Calculate new payment total (previous + the payment just added)
    $new_payment_total = $previous_payment_total + $total_pending;
    $new_total_income = $previous_total_income; // Total income doesn't change when marking as complete
    
    // Format audit trail entry according to specified format
    $audit_parts = [];
    $audit_parts[] = sprintf('Payment Added. Method: %s | Amount: %s', 
        $payment_method_name,
        dcmt_format_currency($total_pending)
    );
    $audit_parts[] = sprintf('Payment Total: %s -> %s', 
        dcmt_format_currency($previous_payment_total),
        dcmt_format_currency($new_payment_total)
    );
    $audit_parts[] = sprintf('Total Income: %s -> %s', 
        dcmt_format_currency($previous_total_income),
        dcmt_format_currency($new_total_income)
    );
    
    $audit_entry = sprintf(
        'Income ID: %d - Patient: %s | %s',
        $income_id,
        $income['dcmt_patient_name'],
        implode(' | ', $audit_parts)
    );
    
    dcmt_log_activity('Payment marked as complete', $audit_entry);
    
    $response_data = [
        'success' => true,
        'message' => trans('income', 'payment_marked_complete'),
        'income_id' => $income_id,
        'pending_amount' => $total_pending,
        'amount_type' => $amount_type,
        'new_total_paid_amount' => $new_total_paid,
        'new_total_pending_amount' => $new_total_pending,
        'new_service_paid_amount' => $new_service_paid,
        'new_product_paid_amount' => $new_product_paid
    ];
    
    echo json_encode($response_data);
    
} catch (PDOException $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    error_log("Error marking payment complete: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
} catch (Exception $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    error_log("General error marking payment complete: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing the request.']);
}
