<?php
/**
 * AJAX Add Patient Checklist Item
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

try {
    dcmt_patient_checklist_ensure_table($dcmt_pdo);

    $result = dcmt_patient_checklist_create($dcmt_pdo, [
        'patient_id' => isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0,
        'title' => isset($_POST['title']) ? trim((string) $_POST['title']) : '',
        'description' => isset($_POST['description']) ? trim((string) $_POST['description']) : '',
    ], $dcmt_current_user);

    if ($result['success']) {
        $item = $result['item'] ?? [];
        dcmt_log_activity(
            'Patient checklist item added',
            'Patient ID: ' . (int) ($item['dcmt_patient_id'] ?? 0) . ', Item ID: ' . (int) ($item['dcmt_id'] ?? 0)
        );

        $result['item'] = [
            'id' => (int) ($item['dcmt_id'] ?? 0),
            'patient_id' => (int) ($item['dcmt_patient_id'] ?? 0),
            'title' => htmlspecialchars((string) ($item['dcmt_title'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'description' => htmlspecialchars((string) ($item['dcmt_description'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'is_completed' => (int) ($item['dcmt_is_completed'] ?? 0),
            'created_by' => htmlspecialchars((string) ($item['created_by_name'] ?? $item['dcmt_created_by'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'created_at' => !empty($item['dcmt_created_at'])
                ? dcmt_format_date($item['dcmt_created_at'], DCMT_DATETIME_FORMAT)
                : '',
        ];
    }

    echo json_encode($result);
} catch (PDOException $e) {
    error_log('Patient checklist add error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('patient_checklist', 'database_error')]);
}
