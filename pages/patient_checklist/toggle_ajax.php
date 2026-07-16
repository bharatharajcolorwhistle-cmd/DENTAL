<?php
/**
 * AJAX Toggle Patient Checklist Item Complete Status
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
$completed = isset($_POST['completed']) ? (int) $_POST['completed'] : -1;
if ($completed !== 0 && $completed !== 1) {
    echo json_encode(['success' => false, 'message' => trans('patient_checklist', 'invalid_id')]);
    exit();
}

try {
    dcmt_patient_checklist_ensure_table($dcmt_pdo);
    $result = dcmt_patient_checklist_toggle($dcmt_pdo, $item_id, $completed === 1, $dcmt_current_user);

    if ($result['success']) {
        dcmt_log_activity(
            $completed === 1 ? 'Patient checklist item completed' : 'Patient checklist item reopened',
            "Item ID: {$item_id}"
        );
        $item = $result['item'] ?? [];
        $result['item'] = [
            'id' => (int) ($item['dcmt_id'] ?? $item_id),
            'is_completed' => (int) ($item['dcmt_is_completed'] ?? $completed),
            'completed_at' => !empty($item['dcmt_completed_at'])
                ? dcmt_format_date($item['dcmt_completed_at'], DCMT_DATETIME_FORMAT)
                : '',
        ];
    }

    echo json_encode($result);
} catch (PDOException $e) {
    error_log('Patient checklist toggle error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('patient_checklist', 'database_error')]);
}
