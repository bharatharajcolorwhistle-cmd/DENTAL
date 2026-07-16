<?php
/**
 * AJAX dismiss lab header notification
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/lab_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$notification_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
    exit();
}

$user_id = (int) ($dcmt_current_user['dcmt_id'] ?? 0);
if ($notification_id <= 0 || $user_id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('lab', 'notification_invalid')]);
    exit();
}

try {
    dcmt_ensure_lab_tables($dcmt_pdo);
    $result = dcmt_lab_dismiss_notification($dcmt_pdo, $notification_id, $user_id);
    if (!$result['success']) {
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? trans('lab', 'notification_invalid'),
        ]);
        exit();
    }
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('Lab notification dismiss error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('lab', 'database_error')]);
}
