<?php
/**
 * AJAX Delete Expense Payment Method
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

// Set JSON response header
header('Content-Type: application/json');

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

// Check admin access
if (!dcmt_is_admin_or_doctor()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'access_denied')]);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Verify CSRF token
if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
    exit();
}

// Get payment method ID
$payment_method_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($payment_method_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('expense_payment_method', 'invalid_payment_method_id')]);
    exit();
}

try {
    // Check if payment method exists and get usage count
    $stmt = $dcmt_pdo->prepare("
        SELECT pm.*, COUNT(e.dcmt_id) as usage_count 
        FROM dcmt_expense_payment_methods pm 
        LEFT JOIN dcmt_expenses e ON pm.dcmt_id = e.dcmt_payment_method_id 
        WHERE pm.dcmt_id = ? 
        GROUP BY pm.dcmt_id
    ");
    $stmt->execute([$payment_method_id]);
    $payment_method = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment_method) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => trans('expense_payment_method', 'payment_method_not_found')]);
        exit();
    }
    
    // Check if payment method is in use
    if ($payment_method['usage_count'] > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => trans('expense_payment_method', 'cannot_delete_in_use'),
            'usage_count' => $payment_method['usage_count']
        ]);
        exit();
    }
    
    // Delete the payment method
    $delete_stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_expense_payment_methods WHERE dcmt_id = ?");
    $delete_result = $delete_stmt->execute([$payment_method_id]);
    
    if ($delete_result && $delete_stmt->rowCount() > 0) {
        // Log activity
        dcmt_log_activity(
            "Expense payment method deleted: " . $payment_method['dcmt_name'], 
            "Expense Payment Method ID: $payment_method_id, Name: " . $payment_method['dcmt_name']
        );
        
        echo json_encode([
            'success' => true, 
            'message' => trans('expense_payment_method', 'delete_success')
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => trans('expense_payment_method', 'delete_error')]);
    }
    
} catch (PDOException $e) {
    error_log("Error deleting expense payment method: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => trans('expense_payment_method', 'database_error')]);
}
?>
