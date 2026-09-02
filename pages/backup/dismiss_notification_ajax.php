<?php
/**
 * AJAX dismiss backup header notification (admin and owner doctor)
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/backup_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

if (!dcmt_backup_user_is_admin($dcmt_current_user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

$notification_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
    exit();
}

$user_id = (int) ($dcmt_current_user['dcmt_id'] ?? 0);
if ($notification_id <= 0 || $user_id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('backup', 'notification_invalid')]);
    exit();
}

try {
    $result = dcmt_backup_dismiss_notification($dcmt_pdo, $notification_id, $user_id);
    if (!$result['success']) {
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? trans('backup', 'notification_invalid'),
        ]);
        exit();
    }
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('Backup notification dismiss error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('reminder', 'database_error')]);
}
