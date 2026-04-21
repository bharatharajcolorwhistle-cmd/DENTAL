<?php
/**
 * AJAX Delete Endpoint for Expense Records
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

// Set JSON response header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

$dcmt_user = dcmt_get_current_user();
$dcmt_role = $dcmt_user['dcmt_role'] ?? '';
if (!in_array($dcmt_role, ['admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

// Get expense ID from input
$expense_id = isset($input['id']) ? (int)$input['id'] : 0;

if ($expense_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid expense ID']);
    exit();
}

// Verify CSRF token
if (!isset($input['csrf_token']) || !dcmt_verify_csrf_token($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token verification failed']);
    exit();
}

try {
    // First, get expense details for logging
    $sql = "SELECT e.*, c.dcmt_name as category_name FROM dcmt_expenses e LEFT JOIN dcmt_expense_categories c ON e.dcmt_category_id = c.dcmt_id WHERE e.dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$expense) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Expense not found']);
        exit();
    }
    
    // Delete the expense
    $delete_sql = "DELETE FROM dcmt_expenses WHERE dcmt_id = ?";
    $delete_stmt = $dcmt_pdo->prepare($delete_sql);
    $delete_stmt->execute([$expense_id]);
    
    $rows_affected = $delete_stmt->rowCount();
    
    if ($rows_affected > 0) {
        // Log activity
        dcmt_log_activity(
            "Expense deleted: " . $expense['dcmt_title'] . " - " . dcmt_format_currency($expense['dcmt_amount']), 
            "Expense ID: $expense_id, Title: " . $expense['dcmt_title'] . ", Amount: " . dcmt_format_currency($expense['dcmt_amount'])
        );
        
        // Set session message for consistent display
        $_SESSION['expense_delete_success'] = trans('expense', 'delete_success');
        
        // Return success response
        echo json_encode([
            'success' => true, 
            'message' => trans('expense', 'delete_success'),
            'reload' => true
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete expense']);
    }
    
} catch (PDOException $e) {
    error_log("Database error deleting expense: " . $e->getMessage());
    dcmt_log_activity("Error deleting expense: " . $e->getMessage(), "error");
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
