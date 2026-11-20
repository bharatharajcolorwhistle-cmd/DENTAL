<?php
/**
 * AJAX mark all header notifications as read (reminders, lab, backup).
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/reminder_functions.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/backup_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('reminder', 'invalid_token')]);
    exit();
}

$user_id = (int) ($dcmt_current_user['dcmt_id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('reminder', 'invalid_id')]);
    exit();
}

try {
    $dismissed = [
        'reminders' => dcmt_reminder_dismiss_all_notifications($dcmt_pdo, $user_id),
        'lab' => 0,
        'backup' => 0,
    ];

    if (dcmt_can_access_lab($dcmt_current_user)) {
        dcmt_ensure_lab_tables($dcmt_pdo);
        $dismissed['lab'] = dcmt_lab_dismiss_all_notifications($dcmt_pdo, $user_id);
    }

    if (dcmt_backup_user_is_admin($dcmt_current_user)) {
        $dismissed['backup'] = dcmt_backup_dismiss_all_notifications($dcmt_pdo, $user_id);
    }

    echo json_encode(['success' => true, 'dismissed' => $dismissed]);
} catch (PDOException $e) {
    error_log('Dismiss all notifications error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('reminder', 'database_error')]);
}
