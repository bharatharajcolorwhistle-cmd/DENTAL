<?php
/**
 * AJAX Delete Endpoint for Doctor Specialization Records
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

if (!dcmt_can_delete_records()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'staff_cannot_delete')]);
    exit();
}


// Check admin access
if (!dcmt_is_admin_or_doctor()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

// Get specialization ID from input
$specialization_id = isset($input['id']) ? (int)$input['id'] : 0;

if ($specialization_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('specialization', 'invalid_id')]);
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
    
    // First, get specialization details for logging
    $sql = "SELECT * FROM dcmt_doctor_specializations WHERE dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$specialization_id]);
    $specialization = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$specialization) {
        $dcmt_pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => trans('specialization', 'not_found')]);
        exit();
    }
    
    // Check if specialization is used by any doctor-role users - prevent deletion if they are
    $usage_check_sql = "SELECT COUNT(*) FROM dcmt_users WHERE dcmt_specialization_id = ? AND dcmt_role = 'doctor'";
    $usage_check_stmt = $dcmt_pdo->prepare($usage_check_sql);
    $usage_check_stmt->execute([$specialization_id]);
    $usage_count = $usage_check_stmt->fetchColumn();
    
    if ($usage_count > 0) {
        $dcmt_pdo->rollBack();
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'message' => trans('specialization', 'cannot_delete_with_doctors'),
            'usage_count' => $usage_count
        ]);
        exit();
    }
    
    // Delete the specialization
    $delete_sql = "DELETE FROM dcmt_doctor_specializations WHERE dcmt_id = ?";
    $delete_stmt = $dcmt_pdo->prepare($delete_sql);
    $delete_stmt->execute([$specialization_id]);
    
    $rows_affected = $delete_stmt->rowCount();
    
    if ($rows_affected > 0) {
        // Log activity
        dcmt_log_activity(
            "Doctor specialization deleted: " . $specialization['dcmt_name'], 
            "specialization_deleted"
        );
        
        // Commit transaction
        $dcmt_pdo->commit();
        
        // Set session message for consistent display
        $_SESSION['specialization_delete_success'] = trans('specialization', 'delete_success');
        
        // Return success response
        echo json_encode([
            'success' => true, 
            'message' => trans('specialization', 'delete_success'),
            'reload' => true
        ]);
    } else {
        $dcmt_pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => trans('specialization', 'delete_failed')]);
    }
    
} catch (PDOException $e) {
    $dcmt_pdo->rollBack();
    error_log("Database error deleting specialization: " . $e->getMessage());
    dcmt_log_activity("Error deleting specialization: " . $e->getMessage(), "error");
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => trans('specialization', 'database_error')]);
}
?>
