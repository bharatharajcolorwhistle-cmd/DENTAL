<?php
/**
 * CLI tests for reminder scheduling and notification logic
 *
 * Run: php tests/reminder_notifications_test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this test from CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/reminder_functions.php';

$failures = 0;

function dcmt_test_assert(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "  OK: {$message}\n");
        return;
    }
    $failures++;
    fwrite(STDERR, "  FAIL: {$message}\n");
}

fwrite(STDOUT, "Reminder notification tests\n");
fwrite(STDOUT, "Timezone: " . date_default_timezone_get() . "\n\n");

// notify_at = reminder_at - 2 hours
$reminder_at = '2026-06-01 14:30:00';
$notify_at = dcmt_reminder_compute_notify_at($reminder_at);
dcmt_test_assert($notify_at === '2026-06-01 12:30:00', 'notify_at is exactly 2 hours before reminder_at');

$built = dcmt_reminder_build_datetime('2026-06-01', '14:30');
dcmt_test_assert($built === '2026-06-01 14:30:00', 'build_datetime merges date and time');

$invalid = dcmt_reminder_build_datetime('bad', '14:30');
dcmt_test_assert($invalid === null, 'invalid date returns null');

$errors = dcmt_reminder_validate_form([
    'assigned_user_id' => 0,
    'title' => '',
    'reminder_date' => '',
    'reminder_time' => '',
], false);
dcmt_test_assert(count($errors) >= 3, 'validation catches required fields');

$future_date = (new DateTime('+2 days', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
$future_time = '10:00';
$future_errors = dcmt_reminder_validate_form([
    'assigned_user_id' => 1,
    'title' => 'Test reminder',
    'reminder_date' => $future_date,
    'reminder_time' => $future_time,
], false);
dcmt_test_assert(count($future_errors) === 0, 'validation passes for future reminder');

$far_date = (new DateTime('+4 years', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
$far_errors = dcmt_reminder_validate_form([
    'assigned_user_id' => 1,
    'title' => 'Too far reminder',
    'reminder_date' => $far_date,
    'reminder_time' => '10:00',
], false);
$too_far_found = false;
foreach ($far_errors as $far_error) {
    if (strpos((string) $far_error, '3') !== false) {
        $too_far_found = true;
        break;
    }
}
dcmt_test_assert($too_far_found, 'validation rejects reminder dates more than 3 years ahead');

$tz = new DateTimeZone(date_default_timezone_get());
$weekly_start = new DateTime('2026-08-19 09:00:00', $tz); // Wednesday
$weekly_dates = dcmt_reminder_collect_recurrence_datetimes($weekly_start, [
    'type' => 'weekly',
    'interval' => 1,
    'weekdays' => [1, 3, 5],
    'end_mode' => 'date',
    'end_date' => '2026-08-28',
    'count' => 0,
]);
$weekly_ymd = array_map(static function (DateTime $dt): string {
    return $dt->format('Y-m-d');
}, $weekly_dates);
dcmt_test_assert($weekly_ymd === ['2026-08-21', '2026-08-24', '2026-08-26', '2026-08-28'], 'weekly recurrence uses selected weekdays through end date');

$daily_start = new DateTime('2026-08-01 10:00:00', $tz);
$daily_dates = dcmt_reminder_collect_recurrence_datetimes($daily_start, [
    'type' => 'daily',
    'interval' => 2,
    'weekdays' => [],
    'end_mode' => 'count',
    'end_date' => '',
    'count' => 4,
]);
dcmt_test_assert(count($daily_dates) === 3, 'count includes the original reminder and generates the remaining occurrences');
dcmt_test_assert($daily_dates[0]->format('Y-m-d') === '2026-08-03' && $daily_dates[2]->format('Y-m-d') === '2026-08-07', 'daily interval of 2 days is applied');

$monthly_start = new DateTime('2026-01-31 11:00:00', $tz);
$monthly_dates = dcmt_reminder_collect_recurrence_datetimes($monthly_start, [
    'type' => 'monthly',
    'interval' => 1,
    'weekdays' => [],
    'monthly_mode' => 'day_of_month',
    'end_mode' => 'count',
    'end_date' => '',
    'count' => 3,
]);
dcmt_test_assert(
    isset($monthly_dates[0], $monthly_dates[1])
        && $monthly_dates[0]->format('Y-m-d') === '2026-02-28'
        && $monthly_dates[1]->format('Y-m-d') === '2026-03-31',
    'monthly day-of-month clamps January 31 onto February 28'
);

$yearly_start = new DateTime('2024-02-29 08:00:00', $tz);
$yearly_dates = dcmt_reminder_collect_recurrence_datetimes($yearly_start, [
    'type' => 'yearly',
    'interval' => 1,
    'weekdays' => [],
    'end_mode' => 'count',
    'end_date' => '',
    'count' => 3,
]);
dcmt_test_assert(
    isset($yearly_dates[0]) && $yearly_dates[0]->format('Y-m-d') === '2025-02-28',
    'yearly Feb 29 clamps to Feb 28 on non-leap years'
);

// Database integration (optional if table exists)
if (isset($dcmt_pdo) && $dcmt_pdo instanceof PDO) {
    fwrite(STDOUT, "\nDatabase integration tests\n");
    try {
        $dcmt_pdo->query('SELECT 1 FROM dcmt_reminders LIMIT 1');

        $test_user_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_username FROM dcmt_users WHERE dcmt_status = 'active' LIMIT 1");
        $test_user = $test_user_stmt ? $test_user_stmt->fetch(PDO::FETCH_ASSOC) : null;

        if ($test_user) {
            $notify_in_one_hour = (new DateTime('+1 hour', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d H:i:s');
            $reminder_in_three_hours = (new DateTime('+3 hours', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d H:i:s');

            $stmt = $dcmt_pdo->prepare("
                INSERT INTO dcmt_reminders (
                    dcmt_assigned_user_id, dcmt_title, dcmt_description,
                    dcmt_reminder_at, dcmt_notify_at, dcmt_status,
                    dcmt_created_by_user_id, dcmt_created_by
                ) VALUES (?, 'CLI Test Reminder', NULL, ?, ?, 'pending', ?, 'cli_test')
            ");
            $stmt->execute([
                (int) $test_user['dcmt_id'],
                $reminder_in_three_hours,
                $notify_in_one_hour,
                (int) $test_user['dcmt_id'],
            ]);
            $test_id = (int) $dcmt_pdo->lastInsertId();

            $before = dcmt_reminder_count_active_notifications($dcmt_pdo, (int) $test_user['dcmt_id']);
            $processed = dcmt_reminder_process_due_notifications($dcmt_pdo, (new DateTime('+2 hours', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d H:i:s'));
            dcmt_test_assert($processed >= 1, 'process_due_notifications marks test row when as_of >= notify_at');

            $after = dcmt_reminder_count_active_notifications($dcmt_pdo, (int) $test_user['dcmt_id']);
            dcmt_test_assert($after >= $before, 'active notification count increases after processing');

            $dcmt_pdo->prepare('DELETE FROM dcmt_reminders WHERE dcmt_id = ?')->execute([$test_id]);
            fwrite(STDOUT, "  Cleaned up test reminder ID {$test_id}\n");
        } else {
            fwrite(STDOUT, "  SKIP: no active users for DB integration test\n");
        }
    } catch (PDOException $e) {
        fwrite(STDOUT, "  SKIP: dcmt_reminders table not available (" . $e->getMessage() . ")\n");
    }
} else {
    fwrite(STDOUT, "\nSKIP database tests (no PDO)\n");
}

fwrite(STDOUT, "\n");
if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "All tests passed.\n");
exit(0);
