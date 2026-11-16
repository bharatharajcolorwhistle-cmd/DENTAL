<?php
/**
 * AJAX Delete Inventory Item
 * Dental Clinic Management System
 */


require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
// Set JSON response headers
header('Cache-Control: no-cache, must-revalidate');

// Check authentication
if (!dcmt_can_delete_records()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => trans('common', 'staff_cannot_delete')
    ]);
    exit();
}

if (!dcmt_is_admin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied.'
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
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_name, dcmt_sku, dcmt_quantity FROM dcmt_inventory WHERE dcmt_id = ?");
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
    
    // Check if item has stock movement / audit history
    try {
        $movement_check_stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_inventory_movements WHERE dcmt_inventory_id = ?");
        $movement_check_stmt->execute([$inventory_id]);
        $movement_count = (int) $movement_check_stmt->fetchColumn();
    } catch (PDOException $e) {
        $movement_count = 0;
    }

    if ($movement_count > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => str_replace('{count}', (string) $movement_count, trans('inventory', 'cannot_delete_has_movements', 'Cannot delete this inventory item. It has {count} stock movement record(s). Mark it inactive instead.'))
        ]);
        exit();
    }

    // Never destroy stock silently: write it off through the stock ledger first.
    if ((float) ($item['dcmt_quantity'] ?? 0) > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => trans('inventory', 'cannot_delete_has_stock', 'Cannot delete this inventory item while it still has stock on hand. Record a stock-out/write-off first or mark it inactive.')
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
