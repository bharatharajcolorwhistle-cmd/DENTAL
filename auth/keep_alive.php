<?php
/**
 * Session Keep-Alive Endpoint
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../config/config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Verify CSRF token
if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Check if user is logged in
if (!dcmt_is_logged_in()) {
    echo json_encode([
        'success' => false, 
        'session_expired' => true,
        'message' => 'Session expired'
    ]);
    exit;
}

// Validate session
if (!dcmt_validate_session()) {
    echo json_encode([
        'success' => false, 
        'session_expired' => true,
        'message' => 'Session expired'
    ]);
    exit;
}

// Extend session
dcmt_extend_session();

// Return success
echo json_encode([
    'success' => true,
    'message' => 'Session extended',
    'timestamp' => time()
]);
?>
