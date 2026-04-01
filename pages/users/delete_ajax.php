<?php
/**
 * AJAX Delete Endpoint for User Records
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

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

// Check admin access
if (!dcmt_is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

// Get user ID from input
$user_id = isset($input['id']) ? (int)$input['id'] : 0;

if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}

// Verify CSRF token
if (!isset($input['csrf_token']) || !dcmt_verify_csrf_token($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token verification failed']);
    exit();
}

// Prevent self-deletion
$current_user = dcmt_get_current_user();
if ($user_id == $current_user['dcmt_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('user', 'cannot_delete_self')]);
    exit();
}

try {
    // Start transaction
    $dcmt_pdo->beginTransaction();
    
    // First, get user details for logging
    $sql = "SELECT * FROM dcmt_users WHERE dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $dcmt_pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => trans('user', 'user_not_found')]);
        exit();
    }
    
    // Check if user has created any records - prevent deletion if they do
    $user_has_records = dcmt_user_has_activity_records($user_id);
    if ($user_has_records) {
        $dcmt_pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => trans('user', 'cannot_delete_user_with_records')]);
        exit();
    }
    
    // Delete the user
    $delete_sql = "DELETE FROM dcmt_users WHERE dcmt_id = ?";
    $delete_stmt = $dcmt_pdo->prepare($delete_sql);
    $delete_stmt->execute([$user_id]);
    
    $rows_affected = $delete_stmt->rowCount();
    
    if ($rows_affected > 0) {
        // Log activity
        dcmt_log_activity(
            "User deleted: {$user['dcmt_username']} ({$user['dcmt_full_name']})", 
            "user_deleted"
        );
        
        // Commit transaction
        $dcmt_pdo->commit();
        
        // Set session message for consistent display
        $_SESSION['user_delete_success'] = trans('user', 'delete_success');
        
        // Return success response
        echo json_encode([
            'success' => true, 
            'message' => trans('user', 'delete_success'),
            'reload' => true
        ]);
    } else {
        $dcmt_pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => trans('user', 'user_not_found_or_deleted')]);
    }
    
} catch (PDOException $e) {
    $dcmt_pdo->rollBack();
    error_log("Database error deleting user: " . $e->getMessage());
    dcmt_log_activity("Error deleting user: " . $e->getMessage(), "error");
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => trans('user', 'database_error')]);
}
?>
