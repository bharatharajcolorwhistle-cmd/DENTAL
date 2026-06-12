<?php
/**
 * AJAX Delete Expense Category
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Set JSON header
header('Content-Type: application/json');

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

if (!dcmt_can_delete_records()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'staff_cannot_delete')]);
    exit();
}


// Check if user is admin
if (!dcmt_is_admin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get and validate input
$category_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$csrf_token = $_POST['csrf_token'] ?? '';

// Validate CSRF token
if (!dcmt_verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit();
}

// Validate category ID
if ($category_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid category ID.']);
    exit();
}

try {
    // Get category data
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_expense_categories WHERE dcmt_id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch();
    
    if (!$category) {
        echo json_encode(['success' => false, 'message' => 'Category not found.']);
        exit();
    }
    
    // Check if category is being used by any expenses
    $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) as count FROM dcmt_expenses WHERE dcmt_category_id = ?");
    $stmt->execute([$category_id]);
    $result = $stmt->fetch();
    $usage_count = $result['count'];
    
    // Prevent deletion if category is being used
    if ($usage_count > 0) {
        echo json_encode([
            'success' => false, 
            'message' => trans('expense_category', 'cannot_delete_used_category'),
            'usage_count' => $usage_count
        ]);
        exit();
    }
    
    // Start transaction
    $dcmt_pdo->beginTransaction();
    
    // Delete the category (only if not used)
    $stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_expense_categories WHERE dcmt_id = ?");
    $stmt->execute([$category_id]);
    
    // Commit transaction
    $dcmt_pdo->commit();
    
    // Log activity
    dcmt_log_activity('Expense category deleted', "Category ID: $category_id, Name: {$category['dcmt_name']}");
    
    echo json_encode([
        'success' => true, 
        'message' => trans('expense_category', 'category_deleted_successfully'),
        'category_id' => $category_id,
        'usage_count' => $usage_count
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    
    error_log("Error deleting expense category: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
}
?>
