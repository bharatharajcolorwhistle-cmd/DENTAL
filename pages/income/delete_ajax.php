<?php
/**
 * AJAX Delete Endpoint for Income Records
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
// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

// Get income ID from input
$income_id = isset($input['id']) ? (int)$input['id'] : 0;

if ($income_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid income ID']);
    exit();
}

// Verify CSRF token
if (!isset($input['csrf_token']) || !dcmt_verify_csrf_token($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token verification failed']);
    exit();
}

if (!dcmt_can_delete_records()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'staff_cannot_delete')]);
    exit();
}

// Check if user has permission to delete this income record
// Admin and doctor roles can delete income records
if (!dcmt_is_admin_or_doctor() && !dcmt_can_access_resource('income', $income_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

try {
    // Start transaction
    $dcmt_pdo->beginTransaction();
    
    // First, get income details for logging
    $sql = "SELECT i.*, u_doctor.dcmt_full_name as doctor_name 
            FROM dcmt_income i 
            LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id 
            WHERE i.dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$income_id]);
    $income = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$income) {
        $dcmt_pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Income record not found']);
        exit();
    }
    
    // Delete related income breakdown rows first (handled by FK but explicit for clarity)
    $delete_items_sql = "DELETE FROM dcmt_income_breakdown WHERE dcmt_id = ?";
    $delete_items_stmt = $dcmt_pdo->prepare($delete_items_sql);
    $delete_items_stmt->execute([$income_id]);
    
    // Delete the income record
    $delete_sql = "DELETE FROM dcmt_income WHERE dcmt_id = ?";
    $delete_stmt = $dcmt_pdo->prepare($delete_sql);
    $delete_stmt->execute([$income_id]);
    
    $rows_affected = $delete_stmt->rowCount();
    
    if ($rows_affected > 0) {
        // Log activity
        dcmt_log_activity(
            "Income record deleted: " . $income['dcmt_patient_name'] . " - " . dcmt_format_currency($income['dcmt_amount']), 
            "Income ID: $income_id | Patient: " . $income['dcmt_patient_name'] . " | Amount: " . dcmt_format_currency($income['dcmt_amount']) . " | Type: " . $income['dcmt_type']
        );
        
        // Commit transaction
        $dcmt_pdo->commit();
        
        // Set session message for consistent display
        $_SESSION['income_delete_success'] = trans('income', 'delete_success');
        
        // Return success response
        echo json_encode([
            'success' => true, 
            'message' => trans('income', 'delete_success'),
            'reload' => true
        ]);
    } else {
        $dcmt_pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete income record']);
    }
    
} catch (PDOException $e) {
    $dcmt_pdo->rollBack();
    error_log("Database error deleting income record: " . $e->getMessage());
    dcmt_log_activity("Error deleting income record: " . $e->getMessage(), "error");
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
