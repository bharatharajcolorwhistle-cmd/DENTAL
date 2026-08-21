<?php
/**
 * AJAX Bulk Delete Endpoint for Service Records
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
    echo json_encode(['success' => false, 'message' => trans('service', 'invalid_json_input', 'Invalid JSON input')]);
    exit();
}

$service_ids = isset($input['ids']) ? $input['ids'] : [];

if (empty($service_ids) || !is_array($service_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('service', 'please_select_one_record')]);
    exit();
}

$service_ids = array_values(array_unique(array_filter(array_map('intval', $service_ids), function ($id) {
    return $id > 0;
})));

if (empty($service_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => trans('service', 'invalid_ids', 'Invalid service IDs')]);
    exit();
}

if (!isset($input['csrf_token']) || !dcmt_verify_csrf_token($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
    exit();
}

try {
    $placeholders = implode(',', array_fill(0, count($service_ids), '?'));

    $sql = "SELECT dcmt_id, dcmt_name FROM dcmt_services WHERE dcmt_id IN ($placeholders)";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($service_ids);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($items) !== count($service_ids)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => trans('service', 'records_not_found', 'One or more services not found')]);
        exit();
    }

    $blocked_ids = [];

    $doctor_sql = "SELECT DISTINCT dcmt_service_id
                   FROM dcmt_doctor_services
                   WHERE dcmt_status = 'active' AND dcmt_service_id IN ($placeholders)";
    $doctor_stmt = $dcmt_pdo->prepare($doctor_sql);
    $doctor_stmt->execute($service_ids);
    $blocked_ids = array_merge($blocked_ids, array_map('intval', $doctor_stmt->fetchAll(PDO::FETCH_COLUMN)));

    $breakdown_sql = "SELECT DISTINCT dcmt_reference_id
                      FROM dcmt_income_breakdown
                      WHERE dcmt_line_type = 'service' AND dcmt_reference_id IN ($placeholders)";
    $breakdown_stmt = $dcmt_pdo->prepare($breakdown_sql);
    $breakdown_stmt->execute($service_ids);
    $blocked_ids = array_merge($blocked_ids, array_map('intval', $breakdown_stmt->fetchAll(PDO::FETCH_COLUMN)));

    $legacy_sql = "SELECT DISTINCT dcmt_service_id
                   FROM dcmt_income
                   WHERE dcmt_service_id IN ($placeholders)";
    $legacy_stmt = $dcmt_pdo->prepare($legacy_sql);
    $legacy_stmt->execute($service_ids);
    $blocked_ids = array_merge($blocked_ids, array_map('intval', $legacy_stmt->fetchAll(PDO::FETCH_COLUMN)));

    $blocked_set = array_flip($blocked_ids);

    $deletable_ids = [];
    $deletable_items = [];
    $skipped_count = 0;

    foreach ($items as $item) {
        $item_id = (int) $item['dcmt_id'];
        if (isset($blocked_set[$item_id])) {
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
            'message' => trans('service', 'cannot_delete_used_service')
        ]);
        exit();
    }

    $dcmt_pdo->beginTransaction();

    $delete_placeholders = implode(',', array_fill(0, count($deletable_ids), '?'));

    $cleanup_stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_doctor_services WHERE dcmt_service_id IN ($delete_placeholders)");
    $cleanup_stmt->execute($deletable_ids);

    $delete_stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_services WHERE dcmt_id IN ($delete_placeholders)");
    $delete_stmt->execute($deletable_ids);
    $rows_affected = $delete_stmt->rowCount();

    if ($rows_affected <= 0) {
        $dcmt_pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => trans('service', 'failed_to_delete_records')]);
        exit();
    }

    foreach ($deletable_items as $item) {
        dcmt_log_activity(
            "Service deleted (bulk)",
            "Service ID: " . $item['dcmt_id'] . " | Name: " . $item['dcmt_name'] . " (Bulk Delete)"
        );
    }

    $dcmt_pdo->commit();

    $deleted_count = count($deletable_items);
    if ($skipped_count > 0) {
        $message = str_replace(
            ['{deleted}', '{skipped}'],
            [(string) $deleted_count, (string) $skipped_count],
            trans('service', 'bulk_delete_partial')
        );
    } elseif ($deleted_count === 1) {
        $message = trans('service', 'delete_success');
    } else {
        $message = str_replace('{count}', (string) $deleted_count, trans('service', 'bulk_delete_success'));
    }

    $_SESSION['service_delete_success'] = $message;

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
    error_log("Database error deleting service records (bulk): " . $e->getMessage());
    dcmt_log_activity("Error deleting service records (bulk): " . $e->getMessage(), "error");

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => trans('service', 'database_error')]);
}
