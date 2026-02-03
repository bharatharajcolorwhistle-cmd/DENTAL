<?php
/**
 * AJAX Delete Income Payment Status
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

// Check admin or doctor access
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

// Get payment status ID
$payment_status_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($payment_status_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('income_payment_status', 'invalid_payment_status_id')]);
    exit();
}

try {
    // Check if payment status exists and get usage count
    $stmt = $dcmt_pdo->prepare("
        SELECT ps.*, COUNT(i.dcmt_id) as usage_count 
        FROM dcmt_income_payment_status ps 
        LEFT JOIN dcmt_income i ON ps.dcmt_id = i.dcmt_payment_status_id 
        WHERE ps.dcmt_id = ? 
        GROUP BY ps.dcmt_id
    ");
    $stmt->execute([$payment_status_id]);
    $payment_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment_status) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => trans('income_payment_status', 'payment_status_not_found')]);
        exit();
    }
    
    // Check if payment status is in use
    if ($payment_status['usage_count'] > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => trans('income_payment_status', 'cannot_delete_in_use'),
            'usage_count' => $payment_status['usage_count']
        ]);
        exit();
    }
    
    // Delete the payment status
    $delete_stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_income_payment_status WHERE dcmt_id = ?");
    $delete_result = $delete_stmt->execute([$payment_status_id]);
    
    if ($delete_result && $delete_stmt->rowCount() > 0) {
        // Log activity
        dcmt_log_activity(
            "Income payment status deleted: " . $payment_status['dcmt_name'], 
            "Income Payment Status ID: $payment_status_id, Name: " . $payment_status['dcmt_name']
        );
        
        echo json_encode([
            'success' => true, 
            'message' => trans('income_payment_status', 'delete_success')
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => trans('income_payment_status', 'delete_error')]);
    }
    
} catch (PDOException $e) {
    error_log("Error deleting income payment status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => trans('income_payment_status', 'database_error')]);
}
?>
