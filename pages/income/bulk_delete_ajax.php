<?php
/**
 * AJAX Bulk Delete Endpoint for Income Records
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

// Get income IDs from input
$income_ids = isset($input['ids']) ? $input['ids'] : [];

if (empty($income_ids) || !is_array($income_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No income IDs provided']);
    exit();
}

// Validate that all IDs are integers
$income_ids = array_map('intval', $income_ids);
$income_ids = array_filter($income_ids, function($id) { return $id > 0; });

if (empty($income_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid income IDs']);
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

// Check if user has permission to delete income records
// Admin and doctor roles can delete income records
if (!dcmt_is_admin_or_doctor()) {
    // For non-admin/doctor users, check each income record individually
    foreach ($income_ids as $income_id) {
        if (!dcmt_can_access_resource('income', $income_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied for one or more income records']);
            exit();
        }
    }
}

try {
    // Start transaction
    $dcmt_pdo->beginTransaction();
    
    // First, get income details for logging
    $placeholders = str_repeat('?,', count($income_ids) - 1) . '?';
    $sql = "SELECT dcmt_id, dcmt_patient_name, dcmt_amount, dcmt_type FROM dcmt_income WHERE dcmt_id IN ($placeholders)";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($income_ids);
    $income_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($income_records) !== count($income_ids)) {
        $dcmt_pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'One or more income records not found']);
        exit();
    }

    require_once __DIR__ . '/../../includes/patient_advance_functions.php';
    require_once __DIR__ . '/../../includes/inventory_functions.php';
    if (isset($dcmt_db) && method_exists($dcmt_db, 'ensureInventoryMovementSchema')) {
        $dcmt_db->ensureInventoryMovementSchema();
    }
    foreach ($income_ids as $income_id_to_reverse) {
        dcmt_patient_advance_reverse_for_income($dcmt_pdo, (int) $income_id_to_reverse);
    }

    // Restore stock for all product lines before the income rows disappear.
    $product_lines_stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_inventory_id, dcmt_quantity
                                              FROM dcmt_income_breakdown
                                              WHERE dcmt_id IN ($placeholders) AND dcmt_line_type = 'product'
                                                AND dcmt_inventory_id IS NOT NULL");
    $product_lines_stmt->execute($income_ids);
    $dcmt_delete_user = (string) (dcmt_get_current_user()['dcmt_username'] ?? 'system');
    foreach ($product_lines_stmt->fetchAll(PDO::FETCH_ASSOC) as $product_line) {
        $sale_unit_cost = dcmt_inventory_sale_unit_cost($dcmt_pdo, (int) $product_line['dcmt_inventory_id'], (int) $product_line['dcmt_id']);
        $restore_result = dcmt_inventory_apply_movement(
            $dcmt_pdo,
            (int) $product_line['dcmt_inventory_id'],
            'sale_return',
            abs((float) $product_line['dcmt_quantity']),
            [
                'unit_cost' => $sale_unit_cost ?? 0,
                'reference_type' => 'income',
                'reference_id' => (int) $product_line['dcmt_id'],
                'notes' => trans('inventory', 'movement_type_sale_return') . ' (income deleted) #' . (int) $product_line['dcmt_id'],
                'movement_date' => date('Y-m-d H:i:s'),
                'created_by' => $dcmt_delete_user,
                'affects_average_cost' => false,
            ]
        );
        if (empty($restore_result['success'])) {
            throw new PDOException($restore_result['error'] ?? 'Inventory restore failed');
        }
    }
    
    // Delete related income breakdown rows first (handled by FK but explicit for clarity)
    $delete_items_sql = "DELETE FROM dcmt_income_breakdown WHERE dcmt_id IN ($placeholders)";
    $delete_items_stmt = $dcmt_pdo->prepare($delete_items_sql);
    $delete_items_stmt->execute($income_ids);
    
    // Delete the income records
    $delete_sql = "DELETE FROM dcmt_income WHERE dcmt_id IN ($placeholders)";
    $delete_stmt = $dcmt_pdo->prepare($delete_sql);
    $delete_stmt->execute($income_ids);
    
    $rows_affected = $delete_stmt->rowCount();
    
    if ($rows_affected > 0) {
        // Log activity for each deleted income record
        foreach ($income_records as $income) {
            dcmt_log_activity(
                "Income record deleted (bulk): " . $income['dcmt_patient_name'] . " - " . dcmt_format_currency($income['dcmt_amount']), 
                "Income ID: " . $income['dcmt_id'] . " | Patient: " . $income['dcmt_patient_name'] . " | Amount: " . dcmt_format_currency($income['dcmt_amount']) . " | Type: " . $income['dcmt_type'] . " (Bulk Delete)"
            );
        }
        
        // Commit transaction
        $dcmt_pdo->commit();
        
        // Set session message for consistent display
        $count = count($income_records);
        if ($count == 1) {
            $_SESSION['income_delete_success'] = trans('income', 'delete_success');
        } else {
            $_SESSION['income_delete_success'] = "$count income records deleted successfully!";
        }
        
        // Return success response
        echo json_encode([
            'success' => true, 
            'message' => $count == 1 ? trans('income', 'delete_success') : "$count income records deleted successfully!",
            'count' => $count,
            'reload' => true
        ]);
    } else {
        $dcmt_pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete income records']);
    }
    
} catch (PDOException $e) {
    $dcmt_pdo->rollBack();
    error_log("Database error deleting income records: " . $e->getMessage());
    dcmt_log_activity("Error deleting income records (bulk): " . $e->getMessage(), "error");
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
