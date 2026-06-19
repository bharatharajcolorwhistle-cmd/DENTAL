<?php
/**
 * AJAX Bulk Delete Endpoint for Expense Records
 * Dental Clinic Management System
 */


require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
// Set JSON response header
// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Enhanced session validation with timeout checking
if (!dcmt_can_delete_records()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'staff_cannot_delete')]);
    exit();
}

if (!dcmt_is_admin()) {
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

// Get expense IDs from input
$expense_ids = isset($input['ids']) ? $input['ids'] : [];

if (empty($expense_ids) || !is_array($expense_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No expense IDs provided']);
    exit();
}

// Validate that all IDs are integers
$expense_ids = array_map('intval', $expense_ids);
$expense_ids = array_filter($expense_ids, function($id) { return $id > 0; });

if (empty($expense_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid expense IDs']);
    exit();
}

// Verify CSRF token
if (!isset($input['csrf_token']) || !dcmt_verify_csrf_token($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token verification failed']);
    exit();
}

try {
    // Start transaction
    $dcmt_pdo->beginTransaction();
    
    // First, get expense details for logging
    $placeholders = str_repeat('?,', count($expense_ids) - 1) . '?';
    $sql = "SELECT dcmt_id, dcmt_title, dcmt_amount FROM dcmt_expenses WHERE dcmt_id IN ($placeholders)";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($expense_ids);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($expenses) !== count($expense_ids)) {
        $dcmt_pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'One or more expenses not found']);
        exit();
    }
    
    // Delete the expenses
    $delete_sql = "DELETE FROM dcmt_expenses WHERE dcmt_id IN ($placeholders)";
    $delete_stmt = $dcmt_pdo->prepare($delete_sql);
    $delete_stmt->execute($expense_ids);
    
    $rows_affected = $delete_stmt->rowCount();
    
    if ($rows_affected > 0) {
        // Log activity for each deleted expense
        foreach ($expenses as $expense) {
            dcmt_log_activity(
                "Expense deleted (bulk): " . $expense['dcmt_title'] . " - " . dcmt_format_currency($expense['dcmt_amount']), 
                "Expense ID: " . $expense['dcmt_id'] . ", Title: " . $expense['dcmt_title'] . ", Amount: " . dcmt_format_currency($expense['dcmt_amount']) . " (Bulk Delete)"
            );
        }
        
        // Commit transaction
        $dcmt_pdo->commit();
        
        // Set session message for consistent display
        $count = count($expenses);
        if ($count == 1) {
            $_SESSION['expense_delete_success'] = trans('expense', 'delete_success');
        } else {
            $_SESSION['expense_delete_success'] = "$count expenses deleted successfully!";
        }
        
        // Return success response
        echo json_encode([
            'success' => true, 
            'message' => $count == 1 ? trans('expense', 'delete_success') : "$count expenses deleted successfully!",
            'count' => $count,
            'reload' => true
        ]);
    } else {
        $dcmt_pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete expenses']);
    }
    
} catch (PDOException $e) {
    $dcmt_pdo->rollBack();
    error_log("Database error deleting expenses: " . $e->getMessage());
    dcmt_log_activity("Error deleting expenses (bulk): " . $e->getMessage(), "error");
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
