<?php
/**
 * AJAX Bulk Delete Endpoint for Inventory Records
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => trans('common', 'method_not_allowed')]);
    exit();
}

if (!dcmt_can_delete_records()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'staff_cannot_delete')]);
    exit();
}

if (!dcmt_is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'access_denied', 'Access denied.')]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('inventory', 'invalid_json_input', 'Invalid JSON input')]);
    exit();
}

$inventory_ids = isset($input['ids']) ? $input['ids'] : [];

if (empty($inventory_ids) || !is_array($inventory_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('inventory', 'please_select_one_record')]);
    exit();
}

$inventory_ids = array_values(array_unique(array_filter(array_map('intval', $inventory_ids), function ($id) {
    return $id > 0;
})));

if (empty($inventory_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('inventory', 'invalid_ids', 'Invalid inventory IDs')]);
    exit();
}

if (!isset($input['csrf_token']) || !dcmt_verify_csrf_token($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
    exit();
}

try {
    $placeholders = implode(',', array_fill(0, count($inventory_ids), '?'));

    $sql = "SELECT dcmt_id, dcmt_name, dcmt_sku FROM dcmt_inventory WHERE dcmt_id IN ($placeholders)";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($inventory_ids);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($items) !== count($inventory_ids)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => trans('inventory', 'records_not_found', 'One or more inventory items not found')]);
        exit();
    }

    $usage_sql = "SELECT DISTINCT dcmt_inventory_id
                  FROM dcmt_income_breakdown
                  WHERE dcmt_line_type = 'product' AND dcmt_inventory_id IN ($placeholders)";
    $usage_stmt = $dcmt_pdo->prepare($usage_sql);
    $usage_stmt->execute($inventory_ids);
    $used_ids = array_map('intval', $usage_stmt->fetchAll(PDO::FETCH_COLUMN));
    $used_set = array_flip($used_ids);

    $deletable_ids = [];
    $deletable_items = [];
    $skipped_count = 0;

    foreach ($items as $item) {
        $item_id = (int) $item['dcmt_id'];
        if (isset($used_set[$item_id])) {
            $skipped_count++;
            continue;
        }
        $deletable_ids[] = $item_id;
        $deletable_items[] = $item;
    }

    if (empty($deletable_ids)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => trans('inventory', 'cannot_delete_used')
        ]);
        exit();
    }

    $dcmt_pdo->beginTransaction();

    $delete_placeholders = implode(',', array_fill(0, count($deletable_ids), '?'));
    $delete_sql = "DELETE FROM dcmt_inventory WHERE dcmt_id IN ($delete_placeholders)";
    $delete_stmt = $dcmt_pdo->prepare($delete_sql);
    $delete_stmt->execute($deletable_ids);
    $rows_affected = $delete_stmt->rowCount();

    if ($rows_affected <= 0) {
        $dcmt_pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => trans('inventory', 'failed_to_delete_records')]);
        exit();
    }

    foreach ($deletable_items as $item) {
        dcmt_log_activity(
            "Inventory Deleted (bulk)",
            "Inventory ID: " . $item['dcmt_id'] . " | Name: " . $item['dcmt_name'] . " | SKU: " . $item['dcmt_sku'] . " (Bulk Delete)"
        );
    }

    $dcmt_pdo->commit();

    $deleted_count = count($deletable_items);
    if ($skipped_count > 0) {
        $message = str_replace(
            ['{deleted}', '{skipped}'],
            [(string) $deleted_count, (string) $skipped_count],
            trans('inventory', 'bulk_delete_partial')
        );
    } elseif ($deleted_count === 1) {
        $message = trans('inventory', 'delete_success');
    } else {
        $message = str_replace('{count}', (string) $deleted_count, trans('inventory', 'bulk_delete_success'));
    }

    $_SESSION['inventory_delete_success'] = $message;

    echo json_encode([
        'success' => true,
        'message' => $message,
        'count' => $deleted_count,
        'skipped' => $skipped_count,
        'reload' => true
    ]);
} catch (PDOException $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    error_log("Database error deleting inventory records (bulk): " . $e->getMessage());
    dcmt_log_activity("Error deleting inventory records (bulk): " . $e->getMessage(), "error");

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => trans('inventory', 'database_error')]);
}
