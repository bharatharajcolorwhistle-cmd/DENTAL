<?php
/**
 * Quick add reminder from header bell dropdown
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/reminder_functions.php';

header('Content-Type: application/json');

if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('reminder', 'invalid_token')]);
    exit();
}

$title = trim((string) dcmt_sanitize_input($_POST['title'] ?? ''));
$reminder_date = trim((string) dcmt_sanitize_input($_POST['reminder_date'] ?? ''));
$reminder_time = trim((string) ($_POST['reminder_time'] ?? ''));

$payload = [
    'assigned_user_id' => (int) ($dcmt_current_user['dcmt_id'] ?? 0),
    'title' => $title,
    'description' => '',
    'reminder_date' => $reminder_date,
    'reminder_time' => $reminder_time,
];

try {
    $result = dcmt_reminder_create($dcmt_pdo, $payload, $dcmt_current_user);
    if (!empty($result['success'])) {
        dcmt_log_activity('Reminder created (quick add)', 'Reminder ID: ' . (int) ($result['id'] ?? 0));
        echo json_encode(['success' => true, 'id' => (int) ($result['id'] ?? 0)]);
        exit();
    }

    $errors = $result['errors'] ?? [trans('reminder', 'database_error')];
    echo json_encode([
        'success' => false,
        'message' => implode(' ', array_map('strval', $errors)),
    ]);
} catch (PDOException $e) {
    error_log('Reminder quick add error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('reminder', 'database_error')]);
}

