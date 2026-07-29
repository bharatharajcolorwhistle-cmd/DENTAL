<?php
require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/reminder_functions.php';

$start = trim((string) ($_GET['start'] ?? ''));
$end = trim((string) ($_GET['end'] ?? ''));
$assignee = (int) ($_GET['assignee'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));

if ($start === '' || $end === '') {
    echo json_encode([]);
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
    echo json_encode($events);
} catch (Throwable $e) {
    error_log('Reminder calendar events error: ' . $e->getMessage());
    echo json_encode([]);
}
