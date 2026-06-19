<?php
/**
 * Poll reminder notifications for header bell (process due + return active list)
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/reminder_functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$user_id = (int) ($dcmt_current_user['dcmt_id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user']);
    exit();
}

try {
    $poll = dcmt_reminder_poll_header_notifications($dcmt_pdo, $user_id, 15);

    $items = [];
    foreach ($poll['notifications'] as $row) {
        $items[] = [
            'id' => (int) $row['dcmt_id'],
            'title' => $row['dcmt_title'] ?? '',
            'reminder_at' => $row['dcmt_reminder_at'] ?? '',
            'reminder_at_display' => dcmt_reminder_format_datetime_display($row['dcmt_reminder_at'] ?? ''),
            'message' => trans('reminder', 'notification_advance_message'),
            'view_url' => DCMT_APP_URL . '/pages/reminders/view.php?id=' . (int) $row['dcmt_id'],
        ];
    }

    echo json_encode([
        'success' => true,
        'count' => (int) $poll['count'],
        'notifications' => $items,
    ]);
} catch (PDOException $e) {
    error_log('Reminder poll error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('reminder', 'database_error')]);
}
