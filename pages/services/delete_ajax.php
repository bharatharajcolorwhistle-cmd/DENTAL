<?php
/**
 * AJAX Delete Service
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
if (!dcmt_is_admin_or_doctor()) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get and validate input
$service_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$csrf_token = $_POST['csrf_token'] ?? '';

// Validate CSRF token
if (!dcmt_verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit();
}

// Validate service ID
if ($service_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid service ID.']);
    exit();
}

try {
    // Get service data
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_services WHERE dcmt_id = ?");
    $stmt->execute([$service_id]);
    $service = $stmt->fetch();
    
    if (!$service) {
        echo json_encode(['success' => false, 'message' => 'Service not found.']);
        exit();
    }
    
    // Check if service is assigned to any doctors
    $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) as count FROM dcmt_doctor_services WHERE dcmt_service_id = ? AND dcmt_status = 'active'");
    $stmt->execute([$service_id]);
    $result = $stmt->fetch();
    $doctor_count = $result['count'];
    
    // Check if service is used in any income records
    $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) as count FROM dcmt_income WHERE dcmt_service_id = ?");
    $stmt->execute([$service_id]);
    $result = $stmt->fetch();
    $income_count = $result['count'];
    
    // Prevent deletion if service is being used
    if ($doctor_count > 0 || $income_count > 0) {
        echo json_encode([
            'success' => false, 
            'message' => trans('service', 'cannot_delete_used_service'),
            'usage_count' => $doctor_count + $income_count
        ]);
        exit();
    }
    
    // Start transaction
    $dcmt_pdo->beginTransaction();
    
    // Delete any inactive doctor-service relationships first
    $stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_doctor_services WHERE dcmt_service_id = ?");
    $stmt->execute([$service_id]);
    
    // Delete the service
    $stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_services WHERE dcmt_id = ?");
    $stmt->execute([$service_id]);
    
    // Commit transaction
    $dcmt_pdo->commit();
    
    // Log activity
    dcmt_log_activity('Service deleted', "Service ID: $service_id, Name: {$service['dcmt_name']}");
    
    echo json_encode([
        'success' => true, 
        'message' => trans('service', 'service_deleted_successfully'),
        'service_id' => $service_id,
        'usage_count' => $doctor_count + $income_count
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    
    error_log("Error deleting service: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
}
?>
