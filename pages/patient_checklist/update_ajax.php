<?php
/**
 * AJAX Update Patient Checklist Item
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

    $result = dcmt_patient_checklist_update($dcmt_pdo, $item_id, [
        'title' => isset($_POST['title']) ? trim((string) $_POST['title']) : '',
        'description' => isset($_POST['description']) ? trim((string) $_POST['description']) : '',
    ], $dcmt_current_user);

    if ($result['success']) {
        $item = $result['item'] ?? [];
        dcmt_log_activity('Patient checklist item updated', 'Item ID: ' . $item_id);

        $result['item'] = [
            'id' => (int) ($item['dcmt_id'] ?? $item_id),
            'title' => htmlspecialchars((string) ($item['dcmt_title'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'description' => htmlspecialchars((string) ($item['dcmt_description'] ?? ''), ENT_QUOTES, 'UTF-8'),
        ];
    }

    echo json_encode($result);
} catch (PDOException $e) {
    error_log('Patient checklist update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('patient_checklist', 'database_error')]);
}
