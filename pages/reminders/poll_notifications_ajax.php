<?php
/**
 * Poll reminder + lab notifications for header bell
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/reminder_functions.php';
require_once __DIR__ . '/../../includes/lab_functions.php';

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
            'id' => 'reminder-' . (int) $row['dcmt_id'],
            'source' => 'reminder',
            'source_id' => (int) $row['dcmt_id'],
            'title' => $row['dcmt_title'] ?? '',
            'reminder_at' => $row['dcmt_reminder_at'] ?? '',
            'reminder_at_display' => dcmt_reminder_format_datetime_display($row['dcmt_reminder_at'] ?? ''),
            'message' => trans('reminder', 'notification_advance_message'),
            'view_url' => DCMT_APP_URL . '/pages/reminders/view.php?id=' . (int) $row['dcmt_id'],
            'can_complete' => true,
            'can_dismiss' => true,
        ];
    }

    $lab_count = 0;
    $role = (string) ($dcmt_current_user['dcmt_role'] ?? '');
    if (in_array($role, ['admin', 'doctor'], true) || dcmt_is_admin()) {
        dcmt_ensure_lab_tables($dcmt_pdo);
        $lab_rows = dcmt_lab_fetch_active_notifications($dcmt_pdo, $user_id, 15);
        $lab_count = count($lab_rows) < 15
            ? count($lab_rows)
            : dcmt_lab_count_active_notifications($dcmt_pdo, $user_id);

        foreach ($lab_rows as $row) {
            $local_id = (int) ($row['dcmt_local_work_order_id'] ?? 0);
            $event = trim((string) ($row['dcmt_event'] ?? ''));
            if ($event === 'CHAT_MESSAGE_RECEIVED' && $local_id > 0) {
                $view_url = DCMT_APP_URL . '/pages/lab_work_orders/index.php?chat=' . $local_id;
            } else {
                $view_url = $local_id > 0
                    ? (DCMT_APP_URL . '/pages/lab_work_orders/view.php?id=' . $local_id)
                    : (DCMT_APP_URL . '/pages/lab_work_orders/index.php');
            }

            $created_display = '';
            if (!empty($row['dcmt_created_at']) && function_exists('dcmt_format_date')) {
                $created_display = dcmt_format_date($row['dcmt_created_at'], defined('DCMT_DATETIME_FORMAT') ? DCMT_DATETIME_FORMAT : 'Y-m-d H:i');
            }

            $items[] = [
                'id' => 'lab-' . (int) $row['dcmt_id'],
                'source' => 'lab',
                'source_id' => (int) $row['dcmt_id'],
                'title' => $row['dcmt_title'] ?? trans('lab', 'notification_verification_title'),
                'reminder_at' => $row['dcmt_created_at'] ?? '',
                'reminder_at_display' => $created_display,
                'message' => $row['dcmt_message'] ?? '',
                'view_url' => $view_url,
                'can_complete' => false,
                'can_dismiss' => true,
            ];
        }
    }

    // Newest first across mixed sources
    usort($items, static function ($a, $b) {
        return strcmp((string) ($b['reminder_at'] ?? ''), (string) ($a['reminder_at'] ?? ''));
    });

    echo json_encode([
        'success' => true,
        'count' => (int) $poll['count'] + (int) $lab_count,
        'notifications' => array_slice($items, 0, 20),
    ]);
} catch (PDOException $e) {
    error_log('Notification poll error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('reminder', 'database_error')]);
}
