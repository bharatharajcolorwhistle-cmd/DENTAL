<?php
/**
 * AJAX Bulk Delete Endpoint for Income Payment Methods
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

if (!dcmt_is_admin_or_doctor()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'access_denied', 'Access denied.')]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('income_payment_method', 'invalid_json_input', 'Invalid JSON input')]);
    exit();
}

$ids = isset($input['ids']) ? $input['ids'] : [];

if (empty($ids) || !is_array($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('income_payment_method', 'please_select_one_record')]);
    exit();
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
    return $id > 0;
})));

if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('income_payment_method', 'invalid_ids')]);
    exit();
}

if (!isset($input['csrf_token']) || !dcmt_verify_csrf_token($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
    exit();
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "SELECT dcmt_id, dcmt_name, dcmt_created_by FROM dcmt_income_payment_methods WHERE dcmt_id IN ($placeholders)";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($ids);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($items) !== count($ids)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => trans('income_payment_method', 'records_not_found')]);
        exit();
    }

    $usage_sql = "SELECT DISTINCT dcmt_payment_method_id FROM dcmt_income WHERE dcmt_payment_method_id IN ($placeholders)";
    $usage_stmt = $dcmt_pdo->prepare($usage_sql);
    $usage_stmt->execute($ids);
    $used_set = array_flip(array_map('intval', $usage_stmt->fetchAll(PDO::FETCH_COLUMN)));

    $deletable_ids = [];
    $deletable_items = [];
    $skipped_count = 0;

    foreach ($items as $item) {
        $item_id = (int) $item['dcmt_id'];
        $is_system = ($item['dcmt_created_by'] ?? '') === 'system';
        if ($is_system || isset($used_set[$item_id])) {
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
            'message' => trans('income_payment_method', 'bulk_delete_none')
        ]);
        exit();
    }

    $dcmt_pdo->beginTransaction();

    $delete_placeholders = implode(',', array_fill(0, count($deletable_ids), '?'));
    $delete_stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_income_payment_methods WHERE dcmt_id IN ($delete_placeholders)");
    $delete_stmt->execute($deletable_ids);
    $rows_affected = $delete_stmt->rowCount();

    if ($rows_affected <= 0) {
        $dcmt_pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => trans('income_payment_method', 'failed_to_delete_records')]);
        exit();
    }

    foreach ($deletable_items as $item) {
        dcmt_log_activity(
            "Income payment method deleted (bulk): " . $item['dcmt_name'],
            "Income Payment Method ID: " . $item['dcmt_id'] . ", Name: " . $item['dcmt_name'] . " (Bulk Delete)"
        );
    }

    $dcmt_pdo->commit();

    $deleted_count = count($deletable_items);
    if ($skipped_count > 0) {
        $message = str_replace(
            ['{deleted}', '{skipped}'],
            [(string) $deleted_count, (string) $skipped_count],
            trans('income_payment_method', 'bulk_delete_partial')
        );
    } elseif ($deleted_count === 1) {
        $message = trans('income_payment_method', 'delete_success');
    } else {
        $message = str_replace('{count}', (string) $deleted_count, trans('income_payment_method', 'bulk_delete_success'));
    }

    $_SESSION['income_payment_method_delete_success'] = $message;

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
    error_log("Database error deleting income payment methods (bulk): " . $e->getMessage());
    dcmt_log_activity("Error deleting income payment methods (bulk): " . $e->getMessage(), "error");

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => trans('income_payment_method', 'database_error')]);
}
