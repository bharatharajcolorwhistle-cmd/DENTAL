<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/reminder_functions.php';

if (!dcmt_validate_session()) {
    http_response_code(401);
    exit('Unauthorized');
}

$ids = [];
if (isset($_GET['ids'])) {
    $raw_ids = $_GET['ids'];
    if (is_array($raw_ids)) {
        foreach ($raw_ids as $rid) {
            $id = (int) $rid;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } else {
        $parts = preg_split('/\s*,\s*/', (string) $raw_ids, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            $id = (int) $part;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
} else {
    $single_id = (int) ($_GET['id'] ?? 0);
    if ($single_id > 0) {
        $ids[] = $single_id;
    }
}

$ids = array_values(array_unique($ids));
if (empty($ids)) {
    http_response_code(400);
    exit('Invalid reminder id(s)');
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dcmt_pdo->prepare("
        SELECT r.dcmt_id, r.dcmt_title, r.dcmt_description, r.dcmt_reminder_at, r.dcmt_status,
               u.dcmt_full_name AS assigned_user_name
        FROM dcmt_reminders r
        LEFT JOIN dcmt_users u ON u.dcmt_id = r.dcmt_assigned_user_id
        WHERE r.dcmt_id IN ($placeholders)
        ORDER BY r.dcmt_reminder_at ASC
    ");
    $stmt->execute($ids);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filtered = [];
    foreach ($reminders as $reminder) {
        $reminder_id = (int) ($reminder['dcmt_id'] ?? 0);
        $full = dcmt_reminder_fetch_by_id($dcmt_pdo, $reminder_id);
        if ($full && dcmt_reminder_user_can_view($full, $dcmt_current_user)) {
            $filtered[] = $full;
        }
    }

    if (empty($filtered)) {
        http_response_code(404);
        exit('Reminder(s) not found');
    }

    $clinic_tz = date_default_timezone_get();
    $dtstamp = gmdate('Ymd\THis\Z');
    $url = DCMT_APP_URL . '/pages/reminders/index.php';

    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//" . addcslashes(DCMT_APP_NAME, "\\,;") . "//EN\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= "X-WR-TIMEZONE:{$clinic_tz}\r\n";

    foreach ($filtered as $reminder) {
        $reminder_id = (int) ($reminder['dcmt_id'] ?? 0);
        if ($reminder_id <= 0) {
            continue;
        }

        $start_local = new DateTime((string) $reminder['dcmt_reminder_at'], new DateTimeZone($clinic_tz));
        $end_local = new DateTime(dcmt_reminder_compute_end_at((string) $reminder['dcmt_reminder_at']), new DateTimeZone($clinic_tz));

        $title = (string) ($reminder['dcmt_title'] ?? '');
        $description = trim((string) ($reminder['dcmt_description'] ?? ''));
        $assignee_names = [];
        foreach ($reminder['_assignees'] ?? [] as $assignee) {
            $name = trim((string) ($assignee['dcmt_full_name'] ?? $assignee['dcmt_username'] ?? ''));
            if ($name !== '') {
                $assignee_names[] = $name;
            }
        }
        if (empty($assignee_names) && !empty($reminder['assigned_user_name'])) {
            $assignee_names[] = (string) $reminder['assigned_user_name'];
        }

        $summary = trans('reminder', 'reminder') . ' - ' . $title;
        $details = trans('reminder', 'assigned_to') . ': ' . implode(', ', $assignee_names);
        if ($description !== '') {
            $details .= "\\n" . trans('reminder', 'description') . ': ' . $description;
        }

        $summary_escaped = addcslashes($summary, "\\,;");
        $description_escaped = addcslashes($details, "\\,;");
        $uid = 'dcmt-reminder-' . $reminder_id . '@' . preg_replace('/^https?:\/\//', '', DCMT_APP_URL);
        $dtstart = $start_local->format('Ymd\THis');
        $dtend = $end_local->format('Ymd\THis');

        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$dtstamp}\r\n";
        $ics .= "DTSTART;TZID={$clinic_tz}:{$dtstart}\r\n";
        $ics .= "DTEND;TZID={$clinic_tz}:{$dtend}\r\n";
        $ics .= "SUMMARY:{$summary_escaped}\r\n";
        $ics .= "DESCRIPTION:{$description_escaped}\r\n";
        $ics .= "URL:" . addcslashes($url, "\\,;") . "\r\n";
        $ics .= "END:VEVENT\r\n";
    }

    $ics .= "END:VCALENDAR\r\n";

    $filename = 'reminders-' . date('Ymd-His') . '.ics';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $ics;
} catch (Throwable $e) {
    error_log('Reminder export ICS error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to export reminder');
}
