<?php
/**
 * Dashboard Summary Toggle API Endpoint
 * Handles saving and retrieving the dashboard summary toggle state
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Enhanced session validation
if (!dcmt_validate_session()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get current user
$current_user = dcmt_get_current_user();
if (!$current_user) {
    http_response_code(401);
    echo json_encode(['error' => 'User not found']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Handle GET request - retrieve toggle state
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $username = $current_user['dcmt_username'];
        
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_dashboard_summary_toggle FROM dcmt_users WHERE dcmt_username = ?");
        $stmt->execute([$username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $toggle_state = (int)$result['dcmt_dashboard_summary_toggle'];
            echo json_encode([
                'success' => true,
                'toggle_state' => $toggle_state,
                'is_enabled' => $toggle_state == 1
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
        }
    }
    // Handle POST request - save toggle state
    else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['toggle_state'])) {
            http_response_code(400);
            echo json_encode(['error' => 'toggle_state is required']);
            exit();
        }
        
        $toggle_state = (int)$input['toggle_state'];
        
        // Validate toggle state (should be 0 or 1)
        if ($toggle_state !== 0 && $toggle_state !== 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid toggle_state. Must be 0 or 1']);
            exit();
        }
        
        $username = $current_user['dcmt_username'];
        
        // Update the toggle state in database
        $stmt = $dcmt_pdo->prepare("UPDATE dcmt_users SET dcmt_dashboard_summary_toggle = ? WHERE dcmt_username = ?");
        $stmt->execute([$toggle_state, $username]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'toggle_state' => $toggle_state,
                'is_enabled' => $toggle_state == 1
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update toggle state']);
        }
    }
    else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    // Log error and return error response
    error_log("Summary toggle API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
?>

