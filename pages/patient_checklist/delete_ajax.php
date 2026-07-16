<?php
/**
 * AJAX Delete Patient Checklist Item
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/patient_checklist_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('patient_checklist', 'invalid_token')]);
    exit();
}

$item_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

try {
    dcmt_patient_checklist_ensure_table($dcmt_pdo);
    $existing = dcmt_patient_checklist_get($dcmt_pdo, $item_id);
    $result = dcmt_patient_checklist_delete($dcmt_pdo, $item_id, $dcmt_current_user);

    if ($result['success'] && $existing) {
        dcmt_log_activity(
            'Patient checklist item deleted',
            'Item ID: ' . $item_id . ', Patient ID: ' . (int) ($existing['dcmt_patient_id'] ?? 0)
        );
        $result['item_id'] = $item_id;
    }

    if (!$result['success'] && ($result['message'] ?? '') === trans('patient_checklist', 'no_delete_permission')) {
        http_response_code(403);
    }

    echo json_encode($result);
} catch (PDOException $e) {
    error_log('Patient checklist delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('patient_checklist', 'database_error')]);
}
