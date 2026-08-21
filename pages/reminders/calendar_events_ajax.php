<?php
require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/reminder_functions.php';

$start = trim((string) ($_GET['start'] ?? ''));
$end = trim((string) ($_GET['end'] ?? ''));
$assignee = (int) ($_GET['assignee'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));

$json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $json_flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

if ($start === '' || $end === '') {
    echo json_encode(['success' => false, 'events' => [], 'message' => trans('reminder', 'invalid_datetime')], $json_flags);
    exit();
}

try {
    $events = dcmt_reminder_fetch_calendar_events(
        $dcmt_pdo,
        $dcmt_current_user,
        $start,
        $end,
        $assignee,
        $status
    );
    echo json_encode(['success' => true, 'events' => $events], $json_flags);
} catch (Throwable $e) {
    error_log('Reminder calendar events error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'events' => [],
        'message' => trans('reminder', 'load_events_failed'),
    ], $json_flags);
}
