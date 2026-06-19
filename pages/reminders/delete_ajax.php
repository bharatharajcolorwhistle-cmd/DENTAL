<?php
/**
 * AJAX Delete Reminder
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/reminder_functions.php';

if (!dcmt_can_delete_records()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'staff_cannot_delete')]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$reminder_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('reminder', 'invalid_token')]);
    exit();
}

if ($reminder_id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('reminder', 'invalid_id')]);
    exit();
}

try {
    $result = dcmt_reminder_delete($dcmt_pdo, $reminder_id, $dcmt_current_user);
    if ($result['success']) {
        dcmt_log_activity('Reminder deleted', "Reminder ID: {$reminder_id}");
    }
    echo json_encode($result);
} catch (PDOException $e) {
    error_log('Reminder delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('reminder', 'database_error')]);
}
