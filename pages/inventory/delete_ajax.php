<?php
/**
 * AJAX Delete Inventory Item
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Check authentication
if (!dcmt_validate_session()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => trans('login', 'session_expired')
    ]);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => trans('common', 'method_not_allowed')
    ]);
    exit();
}

// Get and validate input
$inventory_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$csrf_token = $_POST['csrf_token'] ?? '';

// Validate CSRF token
if (!dcmt_verify_csrf_token($csrf_token)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => trans('common', 'invalid_token')
    ]);
    exit();
}

// Validate inventory ID
if ($inventory_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => trans('inventory', 'invalid_inventory_id')
    ]);
    exit();
}

try {
    // Check if inventory item exists
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_name, dcmt_sku FROM dcmt_inventory WHERE dcmt_id = ?");
    $stmt->execute([$inventory_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => trans('inventory', 'item_not_found')
        ]);
        exit();
    }
    
    // Check if item is used in income records
    $usage_check_stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_income_breakdown WHERE dcmt_line_type = 'product' AND dcmt_inventory_id = ?");
    $usage_check_stmt->execute([$inventory_id]);
    $usage_count = $usage_check_stmt->fetchColumn();
    
    if ($usage_count > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => trans('inventory', 'cannot_delete_used_in_income', "Cannot delete this inventory item. It is used in $usage_count income record(s).")
        ]);
        exit();
    }
    
    // Delete the inventory item
    $delete_stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_inventory WHERE dcmt_id = ?");
    $delete_stmt->execute([$inventory_id]);
    
    if ($delete_stmt->rowCount() > 0) {
        // Log activity
        dcmt_log_activity(
            "Inventory Deleted", 
            "Inventory ID: $inventory_id | Name: " . $item['dcmt_name'] . " | SKU: " . $item['dcmt_sku']
        );
        
        echo json_encode([
            'success' => true,
            'message' => trans('inventory', 'delete_success')
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => trans('inventory', 'delete_failed')
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Error deleting inventory item: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => trans('inventory', 'database_error')
    ]);
}
?>
