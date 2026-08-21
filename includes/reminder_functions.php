<?php
/**
 * Reminder module service functions
 * Dental Clinic Management System
 */

if (!defined('DCMT_REMINDER_ADVANCE_HOURS')) {
    define('DCMT_REMINDER_ADVANCE_HOURS', 2);
}

if (!defined('DCMT_REMINDER_CALENDAR_DURATION_MINUTES')) {
    define('DCMT_REMINDER_CALENDAR_DURATION_MINUTES', 30);
}

if (!defined('DCMT_REMINDER_RECURRENCE_MAX_INSTANCES')) {
    define('DCMT_REMINDER_RECURRENCE_MAX_INSTANCES', 365);
}

if (!defined('DCMT_REMINDER_RECURRENCE_MAX_INTERVAL')) {
    define('DCMT_REMINDER_RECURRENCE_MAX_INTERVAL', 99);
}

if (!defined('DCMT_REMINDER_MAX_YEARS_AHEAD')) {
    define('DCMT_REMINDER_MAX_YEARS_AHEAD', 3);
}

/**
 * Latest date a reminder (or recurrence instance) may be scheduled, from today.
 */
function dcmt_reminder_max_allowed_date(): string
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = new DateTime('today', $tz);
    $dt->modify('+' . (int) DCMT_REMINDER_MAX_YEARS_AHEAD . ' years');
    return $dt->format('Y-m-d');
}

/**
 * Compute notify_at datetime (2 hours before reminder_at) in app timezone.
 */
function dcmt_reminder_compute_notify_at(string $reminder_at): string
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $reminder_at, $tz);
    if (!$dt) {
        $dt = new DateTime($reminder_at, $tz);
    }
    $dt->modify('-' . (int) DCMT_REMINDER_ADVANCE_HOURS . ' hours');
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Build reminder_at from separate date and time fields.
 */
function dcmt_reminder_build_datetime(string $date, string $time): ?string
{
    $date = trim($date);
    $time = trim($time);
    if ($date === '' || $time === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        return null;
    }
    if (strlen($time) === 5) {
        $time .= ':00';
    }
    $combined = $date . ' ' . $time;
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $combined, $tz);
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Validate reminder form payload; returns list of error messages.
 *
 * @param array<string,mixed> $data
 * @return array<int,string>
 */
function dcmt_reminder_validate_form(array $data, bool $is_update = false): array
{
    $errors = [];

    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        $errors[] = trans('reminder', 'title_required');
    } elseif (strlen($title) > 255) {
        $errors[] = trans('reminder', 'title_too_long');
    }

    $reminder_date = trim((string) ($data['reminder_date'] ?? ''));
    $reminder_time = trim((string) ($data['reminder_time'] ?? ''));

    if ($reminder_date === '') {
        $errors[] = trans('reminder', 'reminder_date_required');
    } else {
        $date_error = dcmt_validate_date_field($reminder_date, trans('reminder', 'reminder_date'));
        if ($date_error) {
            $errors[] = $date_error;
        }
    }

    if ($reminder_time === '') {
        $errors[] = trans('reminder', 'reminder_time_required');
    }

    $reminder_at = dcmt_reminder_build_datetime($reminder_date, $reminder_time);
    if ($reminder_at === null && $reminder_date !== '' && $reminder_time !== '') {
        $errors[] = trans('reminder', 'invalid_datetime');
    } elseif ($reminder_at !== null && !$is_update) {
        $now = dcmt_get_current_datetime('Y-m-d H:i:s');
        if ($reminder_at <= $now) {
            $errors[] = trans('reminder', 'reminder_must_be_future');
        }
    } elseif ($reminder_at !== null && $is_update && ($data['status'] ?? 'pending') === 'pending') {
        $now = dcmt_get_current_datetime('Y-m-d H:i:s');
        if ($reminder_at <= $now) {
            $errors[] = trans('reminder', 'reminder_must_be_future');
        }
    }

    $max_allowed_date = dcmt_reminder_max_allowed_date();
    $too_far_message = str_replace(
        '{years}',
        (string) DCMT_REMINDER_MAX_YEARS_AHEAD,
        trans('reminder', 'reminder_too_far_ahead')
    );
    if ($reminder_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reminder_date) && $reminder_date > $max_allowed_date) {
        $errors[] = $too_far_message;
    }

    $assigned_user_id = (int) ($data['assigned_user_id'] ?? 0);
    $assignee_ids = $data['assignee_ids'] ?? null;
    if (is_array($assignee_ids)) {
        $assignee_ids = array_values(array_unique(array_filter(array_map('intval', $assignee_ids))));
        if (empty($assignee_ids) && $assigned_user_id > 0) {
            $assignee_ids = [$assigned_user_id];
        }
    } elseif ($assigned_user_id > 0) {
        $assignee_ids = [$assigned_user_id];
    } else {
        $assignee_ids = [];
    }

    if (empty($assignee_ids)) {
        $errors[] = trans('reminder', 'assigned_user_required');
    }

    $raw_recurrence_type = strtolower(trim((string) ($data['recurrence_type'] ?? 'none')));
    if ($raw_recurrence_type !== '' && !in_array($raw_recurrence_type, ['none', 'daily', 'weekly', 'monthly', 'yearly'], true)) {
        $errors[] = trans('reminder', 'invalid_recurrence');
    }

    $recurrence = dcmt_reminder_normalize_recurrence_rule($data);
    $recurrence_type = $recurrence['type'];

    if ($recurrence_type !== 'none') {
        if ($recurrence['interval'] < 1 || $recurrence['interval'] > (int) DCMT_REMINDER_RECURRENCE_MAX_INTERVAL) {
            $errors[] = trans('reminder', 'recurrence_interval_invalid');
        }
        if ($recurrence_type === 'weekly' && empty($recurrence['weekdays'])) {
            $errors[] = trans('reminder', 'recurrence_weekdays_required');
        }
        if ($recurrence['end_mode'] === 'date') {
            $recurrence_end_date = $recurrence['end_date'];
            if ($recurrence_end_date === '') {
                $errors[] = trans('reminder', 'recurrence_end_required');
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recurrence_end_date)) {
                $errors[] = trans('reminder', 'invalid_recurrence');
            } elseif ($reminder_date !== '' && $recurrence_end_date < $reminder_date) {
                $errors[] = trans('reminder', 'recurrence_end_after_start');
            } elseif ($recurrence_end_date > $max_allowed_date) {
                $errors[] = $too_far_message;
            }
        } elseif ($recurrence['end_mode'] === 'count') {
            if ($recurrence['count'] < 2 || $recurrence['count'] > (int) DCMT_REMINDER_RECURRENCE_MAX_INSTANCES) {
                $errors[] = trans('reminder', 'recurrence_count_invalid');
            }
        } elseif ($recurrence['end_mode'] !== 'never') {
            $errors[] = trans('reminder', 'invalid_recurrence');
        }
    }

    return $errors;
}

/**
 * @param array<string,mixed>|null $user
 */
function dcmt_reminder_user_is_admin(?array $user): bool
{
    if (!$user) {
        return false;
    }
    return dcmt_is_admin();
}

/**
 * @param array<string,mixed> $reminder
 * @param array<string,mixed>|null $user
 */
function dcmt_reminder_user_can_view(array $reminder, ?array $user): bool
{
    if (!$user) {
        return false;
    }
    if (dcmt_reminder_user_is_admin($user)) {
        return true;
    }
    $uid = (int) ($user['dcmt_id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    if ($uid === (int) ($reminder['dcmt_assigned_user_id'] ?? 0)
        || $uid === (int) ($reminder['dcmt_created_by_user_id'] ?? 0)) {
        return true;
    }
    $assignee_ids = $reminder['_assignee_ids'] ?? [];
    if (is_array($assignee_ids) && in_array($uid, $assignee_ids, true)) {
        return true;
    }
    return false;
}

/**
 * @param array<string,mixed> $reminder
 * @param array<string,mixed>|null $user
 */
function dcmt_reminder_user_can_manage(array $reminder, ?array $user): bool
{
    return dcmt_reminder_user_can_view($reminder, $user);
}

/**
 * SQL fragment and params for scoping list queries to current user.
 *
 * @return array{0:string,1:array<int,mixed>}
 */
function dcmt_reminder_list_scope_sql(?array $user, string $alias = 'r'): array
{
    if (!$user) {
        return ['1=0', []];
    }
    if (dcmt_reminder_user_is_admin($user)) {
        return ['1=1', []];
    }
    $uid = (int) ($user['dcmt_id'] ?? 0);
    return [
        "({$alias}.dcmt_assigned_user_id = ? OR {$alias}.dcmt_created_by_user_id = ? OR {$alias}.dcmt_id IN (SELECT dcmt_reminder_id FROM dcmt_reminder_assignees WHERE dcmt_user_id = ?))",
        [$uid, $uid, $uid],
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function dcmt_reminder_get_assignable_users(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT dcmt_id, dcmt_full_name, dcmt_username, dcmt_role
        FROM dcmt_users
        WHERE dcmt_status = 'active'
          AND dcmt_role IN ('admin', 'doctor', 'staff', 'assistant')
        ORDER BY dcmt_full_name ASC, dcmt_username ASC
    ");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * @return array<string,mixed>|null
 */
function dcmt_reminder_fetch_by_id(PDO $pdo, int $reminder_id): ?array
{
    if ($reminder_id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT r.*,
               u.dcmt_full_name AS assigned_user_name,
               c.dcmt_full_name AS created_by_full_name
        FROM dcmt_reminders r
        LEFT JOIN dcmt_users u ON u.dcmt_id = r.dcmt_assigned_user_id
        LEFT JOIN dcmt_users c ON c.dcmt_id = r.dcmt_created_by_user_id
        WHERE r.dcmt_id = ?
        LIMIT 1
    ");
    $stmt->execute([$reminder_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['_assignee_ids'] = dcmt_reminder_fetch_assignee_ids($pdo, $reminder_id);
    $row['_assignees'] = dcmt_reminder_fetch_assignees($pdo, $reminder_id);
    return $row;
}

/**
 * Process due advance notifications (notify_at <= now).
 *
 * @return int Number of reminders marked as notified
 */
function dcmt_reminder_process_due_notifications(PDO $pdo, ?string $as_of = null): int
{
    $as_of = $as_of ?? dcmt_get_current_datetime('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE dcmt_reminders
        SET dcmt_notification_sent = 1,
            dcmt_notification_sent_at = ?,
            dcmt_updated_at = CURRENT_TIMESTAMP
        WHERE dcmt_status = 'pending'
          AND dcmt_notification_sent = 0
          AND dcmt_notify_at <= ?
    ");
    $stmt->execute([$as_of, $as_of]);
    return (int) $stmt->rowCount();
}

/**
 * Active header notifications for a user (sent, not dismissed, still pending).
 *
 * @return array<int,array<string,mixed>>
 */
function dcmt_reminder_fetch_active_notifications(PDO $pdo, int $user_id, int $limit = 20): array
{
    if ($user_id <= 0) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.dcmt_id,
               r.dcmt_title,
               r.dcmt_description,
               r.dcmt_reminder_at,
               r.dcmt_notify_at,
               r.dcmt_notification_sent_at
        FROM dcmt_reminders r
        LEFT JOIN dcmt_reminder_assignees a ON a.dcmt_reminder_id = r.dcmt_id
        WHERE r.dcmt_status = 'pending'
          AND r.dcmt_notification_sent = 1
          AND r.dcmt_notification_dismissed = 0
          AND (r.dcmt_assigned_user_id = ? OR a.dcmt_user_id = ?)
        ORDER BY r.dcmt_reminder_at ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dcmt_reminder_count_active_notifications(PDO $pdo, int $user_id): int
{
    if ($user_id <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.dcmt_id)
        FROM dcmt_reminders r
        LEFT JOIN dcmt_reminder_assignees a ON a.dcmt_reminder_id = r.dcmt_id
        WHERE r.dcmt_status = 'pending'
          AND r.dcmt_notification_sent = 1
          AND r.dcmt_notification_dismissed = 0
          AND (r.dcmt_assigned_user_id = ? OR a.dcmt_user_id = ?)
    ");
    $stmt->execute([$user_id, $user_id]);
    return (int) $stmt->fetchColumn();
}

/**
 * Process due reminders and return header bell payload (optimized for polling).
 *
 * @return array{count:int,notifications:array<int,array<string,mixed>>}
 */
function dcmt_reminder_poll_header_notifications(PDO $pdo, int $user_id, int $limit = 15): array
{
    if ($user_id <= 0) {
        return ['count' => 0, 'notifications' => []];
    }

    dcmt_reminder_process_due_notifications($pdo);
    $notifications = dcmt_reminder_fetch_active_notifications($pdo, $user_id, $limit);
    $fetched = count($notifications);

    // Skip an extra COUNT query when the result set is smaller than the limit.
    $count = $fetched < $limit
        ? $fetched
        : dcmt_reminder_count_active_notifications($pdo, $user_id);

    return [
        'count' => $count,
        'notifications' => $notifications,
    ];
}

/**
 * @param array<string,mixed> $payload
 * @return array{success:bool,errors?:array<int,string>,id?:int}
 */
function dcmt_reminder_create(PDO $pdo, array $payload, array $current_user): array
{
    $errors = dcmt_reminder_validate_form($payload, false);
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $assignee_ids = dcmt_reminder_normalize_assignee_ids($payload);
    $assigned_user_id = $assignee_ids[0];

    $reminder_at = dcmt_reminder_build_datetime(
        (string) $payload['reminder_date'],
        (string) $payload['reminder_time']
    );
    if ($reminder_at === null) {
        return ['success' => false, 'errors' => [trans('reminder', 'invalid_datetime')]];
    }

    $notify_at = dcmt_reminder_compute_notify_at($reminder_at);
    $title = trim((string) $payload['title']);
    $description = trim((string) ($payload['description'] ?? ''));
    $description = $description !== '' ? $description : null;
    $priority = dcmt_reminder_normalize_priority($payload['priority'] ?? 'medium');
    $category = trim((string) ($payload['category'] ?? ''));
    $category = $category !== '' ? $category : null;
    $recurrence = dcmt_reminder_normalize_recurrence_rule($payload);
    $recurrence_type = $recurrence['type'];
    $recurrence_end_date = $recurrence_type !== 'none' && $recurrence['end_mode'] === 'date'
        ? $recurrence['end_date']
        : null;
    $recurrence_interval = $recurrence_type !== 'none' ? $recurrence['interval'] : 1;
    $recurrence_weekdays = ($recurrence_type === 'weekly' && !empty($recurrence['weekdays']))
        ? implode(',', $recurrence['weekdays'])
        : null;
    $recurrence_monthly_mode = $recurrence_type === 'monthly' ? $recurrence['monthly_mode'] : null;
    $recurrence_end_mode = $recurrence_type !== 'none' ? $recurrence['end_mode'] : 'date';
    $recurrence_count = ($recurrence_type !== 'none' && $recurrence['end_mode'] === 'count')
        ? $recurrence['count']
        : null;
    $is_recurring = $recurrence_type !== 'none' ? 1 : 0;

    $creator_id = (int) ($current_user['dcmt_id'] ?? 0);
    $created_by = (string) ($current_user['dcmt_username'] ?? 'system');

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO dcmt_reminders (
                dcmt_assigned_user_id,
                dcmt_title,
                dcmt_description,
                dcmt_priority,
                dcmt_category,
                dcmt_recurrence_type,
                dcmt_recurrence_interval,
                dcmt_recurrence_weekdays,
                dcmt_recurrence_monthly_mode,
                dcmt_recurrence_end_mode,
                dcmt_recurrence_count,
                dcmt_recurrence_end_date,
                dcmt_is_recurring,
                dcmt_reminder_at,
                dcmt_notify_at,
                dcmt_status,
                dcmt_created_by_user_id,
                dcmt_created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ");
        $stmt->execute([
            $assigned_user_id,
            $title,
            $description,
            $priority,
            $category,
            $recurrence_type,
            $recurrence_interval,
            $recurrence_weekdays,
            $recurrence_monthly_mode,
            $recurrence_end_mode,
            $recurrence_count,
            $recurrence_end_date,
            $is_recurring,
            $reminder_at,
            $notify_at,
            $creator_id,
            $created_by,
        ]);

        $reminder_id = (int) $pdo->lastInsertId();
        dcmt_reminder_sync_assignees($pdo, $reminder_id, $assignee_ids);

        if ($recurrence_type !== 'none') {
            dcmt_reminder_generate_recurring_instances(
                $pdo,
                $reminder_id,
                $reminder_at,
                $recurrence,
                $payload,
                $current_user,
                $assignee_ids
            );
        }

        $pdo->commit();
        return ['success' => true, 'id' => $reminder_id];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('dcmt_reminder_create failed: ' . $e->getMessage());
        return ['success' => false, 'errors' => [trans('reminder', 'database_error')]];
    }
}

/**
 * @param array<string,mixed> $payload
 * @return array{success:bool,errors?:array<int,string>}
 */
function dcmt_reminder_update(PDO $pdo, int $reminder_id, array $payload, array $current_user): array
{
    $existing = dcmt_reminder_fetch_by_id($pdo, $reminder_id);
    if (!$existing) {
        return ['success' => false, 'errors' => [trans('reminder', 'not_found')]];
    }
    if (!dcmt_reminder_user_can_manage($existing, $current_user)) {
        return ['success' => false, 'errors' => [trans('reminder', 'no_permission')]];
    }

    $payload['status'] = $existing['dcmt_status'] ?? 'pending';
    $errors = dcmt_reminder_validate_form($payload, true);
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $assignee_ids = dcmt_reminder_normalize_assignee_ids($payload);
    $assigned_user_id = $assignee_ids[0];

    $reminder_at = dcmt_reminder_build_datetime(
        (string) $payload['reminder_date'],
        (string) $payload['reminder_time']
    );
    if ($reminder_at === null) {
        return ['success' => false, 'errors' => [trans('reminder', 'invalid_datetime')]];
    }

    $notify_at = dcmt_reminder_compute_notify_at($reminder_at);
    $title = trim((string) $payload['title']);
    $description = trim((string) ($payload['description'] ?? ''));
    $description = $description !== '' ? $description : null;
    $priority = dcmt_reminder_normalize_priority($payload['priority'] ?? ($existing['dcmt_priority'] ?? 'medium'));
    $category = trim((string) ($payload['category'] ?? ''));
    $category = $category !== '' ? $category : null;

    $existing_assignees = $existing['_assignee_ids'] ?? [];
    sort($existing_assignees);
    $new_assignees = $assignee_ids;
    sort($new_assignees);

    $reset_notification = (
        ($existing['dcmt_reminder_at'] ?? '') !== $reminder_at
        || $existing_assignees !== $new_assignees
    );

    if ($reset_notification) {
        $stmt = $pdo->prepare("
            UPDATE dcmt_reminders
            SET dcmt_assigned_user_id = ?,
                dcmt_title = ?,
                dcmt_description = ?,
                dcmt_priority = ?,
                dcmt_category = ?,
                dcmt_reminder_at = ?,
                dcmt_notify_at = ?,
                dcmt_notification_sent = 0,
                dcmt_notification_sent_at = NULL,
                dcmt_notification_dismissed = 0,
                dcmt_updated_at = CURRENT_TIMESTAMP
            WHERE dcmt_id = ?
        ");
        $stmt->execute([
            $assigned_user_id,
            $title,
            $description,
            $priority,
            $category,
            $reminder_at,
            $notify_at,
            $reminder_id,
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE dcmt_reminders
            SET dcmt_assigned_user_id = ?,
                dcmt_title = ?,
                dcmt_description = ?,
                dcmt_priority = ?,
                dcmt_category = ?,
                dcmt_reminder_at = ?,
                dcmt_notify_at = ?,
                dcmt_updated_at = CURRENT_TIMESTAMP
            WHERE dcmt_id = ?
        ");
        $stmt->execute([
            $assigned_user_id,
            $title,
            $description,
            $priority,
            $category,
            $reminder_at,
            $notify_at,
            $reminder_id,
        ]);
    }

    dcmt_reminder_sync_assignees($pdo, $reminder_id, $assignee_ids);

    return ['success' => true];
}

function dcmt_reminder_delete(PDO $pdo, int $reminder_id, array $current_user): array
{
    $existing = dcmt_reminder_fetch_by_id($pdo, $reminder_id);
    if (!$existing) {
        return ['success' => false, 'message' => trans('reminder', 'not_found')];
    }
    if (!dcmt_reminder_user_can_manage($existing, $current_user)) {
        return ['success' => false, 'message' => trans('reminder', 'no_permission')];
    }

    $stmt = $pdo->prepare('DELETE FROM dcmt_reminders WHERE dcmt_id = ?');
    $stmt->execute([$reminder_id]);
    return ['success' => true, 'message' => trans('reminder', 'delete_success')];
}

function dcmt_reminder_mark_completed(PDO $pdo, int $reminder_id, array $current_user): array
{
    $existing = dcmt_reminder_fetch_by_id($pdo, $reminder_id);
    if (!$existing) {
        return ['success' => false, 'message' => trans('reminder', 'not_found')];
    }
    if (!dcmt_reminder_user_can_manage($existing, $current_user)) {
        return ['success' => false, 'message' => trans('reminder', 'no_permission')];
    }

    $stmt = $pdo->prepare("
        UPDATE dcmt_reminders
        SET dcmt_status = 'completed',
            dcmt_completed_at = ?,
            dcmt_updated_at = CURRENT_TIMESTAMP
        WHERE dcmt_id = ?
    ");
    $stmt->execute([dcmt_get_current_datetime('Y-m-d H:i:s'), $reminder_id]);
    return ['success' => true, 'message' => trans('reminder', 'complete_success')];
}

function dcmt_reminder_dismiss_notification(PDO $pdo, int $reminder_id, array $current_user): array
{
    $existing = dcmt_reminder_fetch_by_id($pdo, $reminder_id);
    if (!$existing) {
        return ['success' => false, 'message' => trans('reminder', 'not_found')];
    }

    $uid = (int) ($current_user['dcmt_id'] ?? 0);
    $is_assignee = (int) ($existing['dcmt_assigned_user_id'] ?? 0) === $uid;
    if (!$is_assignee) {
        $assignee_ids = $existing['_assignee_ids'] ?? dcmt_reminder_fetch_assignee_ids($pdo, $reminder_id);
        $is_assignee = in_array($uid, $assignee_ids, true);
    }
    if (!$is_assignee && !dcmt_reminder_user_is_admin($current_user)) {
        return ['success' => false, 'message' => trans('reminder', 'no_permission')];
    }

    $stmt = $pdo->prepare("
        UPDATE dcmt_reminders
        SET dcmt_notification_dismissed = 1,
            dcmt_updated_at = CURRENT_TIMESTAMP
        WHERE dcmt_id = ?
    ");
    $stmt->execute([$reminder_id]);
    return ['success' => true];
}

function dcmt_reminder_format_datetime_display(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return htmlspecialchars((string) $datetime);
    }
    return date('d/m/Y h:i A', $ts);
}

/**
 * @param array<string,mixed> $payload
 * @return array<int,int>
 */
function dcmt_reminder_normalize_assignee_ids(array $payload): array
{
    $assigned_user_id = (int) ($payload['assigned_user_id'] ?? 0);
    $assignee_ids = $payload['assignee_ids'] ?? null;
    if (is_array($assignee_ids)) {
        $assignee_ids = array_values(array_unique(array_filter(array_map('intval', $assignee_ids))));
    } else {
        $assignee_ids = [];
    }
    if (empty($assignee_ids) && $assigned_user_id > 0) {
        $assignee_ids = [$assigned_user_id];
    }
    return $assignee_ids;
}

function dcmt_reminder_normalize_priority(string $priority): string
{
    $priority = strtolower(trim($priority));
    return in_array($priority, ['low', 'medium', 'high'], true) ? $priority : 'medium';
}

function dcmt_reminder_normalize_recurrence_type(string $type): string
{
    $type = strtolower(trim($type));
    return in_array($type, ['none', 'daily', 'weekly', 'monthly', 'yearly'], true) ? $type : 'none';
}

/**
 * @param mixed $raw
 * @return array<int,int>
 */
function dcmt_reminder_normalize_weekdays($raw): array
{
    if (is_string($raw)) {
        $raw = $raw === '' ? [] : preg_split('/\s*,\s*/', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }
    $days = [];
    foreach ($raw as $value) {
        $day = (int) $value;
        if ($day >= 0 && $day <= 6) {
            $days[$day] = $day;
        }
    }
    $days = array_values($days);
    sort($days);
    return $days;
}

/**
 * @param array<string,mixed> $data
 * @return array{
 *   type:string,
 *   interval:int,
 *   weekdays:array<int,int>,
 *   monthly_mode:string,
 *   end_mode:string,
 *   end_date:string,
 *   count:int
 * }
 */
function dcmt_reminder_normalize_recurrence_rule(array $data): array
{
    $type = dcmt_reminder_normalize_recurrence_type((string) ($data['recurrence_type'] ?? 'none'));
    $interval = (int) ($data['recurrence_interval'] ?? 1);
    if ($interval < 1) {
        $interval = 1;
    }
    $weekdays = dcmt_reminder_normalize_weekdays($data['recurrence_weekdays'] ?? []);
    $reminder_date = trim((string) ($data['reminder_date'] ?? ''));
    if ($type === 'weekly' && empty($weekdays) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reminder_date)) {
        $weekdays = [(int) date('w', strtotime($reminder_date . ' 12:00:00'))];
    }

    $monthly_mode = strtolower(trim((string) ($data['recurrence_monthly_mode'] ?? 'day_of_month')));
    if (!in_array($monthly_mode, ['day_of_month', 'nth_weekday'], true)) {
        $monthly_mode = 'day_of_month';
    }

    $end_mode = strtolower(trim((string) ($data['recurrence_end_mode'] ?? 'date')));
    if (!in_array($end_mode, ['date', 'count', 'never'], true)) {
        $end_mode = 'date';
    }

    $count = (int) ($data['recurrence_count'] ?? 0);

    return [
        'type' => $type,
        'interval' => $interval,
        'weekdays' => $weekdays,
        'monthly_mode' => $monthly_mode,
        'end_mode' => $type === 'none' ? 'date' : $end_mode,
        'end_date' => trim((string) ($data['recurrence_end_date'] ?? '')),
        'count' => $count,
    ];
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function dcmt_reminder_rule_from_row(array $row): array
{
    $start_at = (string) ($row['dcmt_reminder_at'] ?? '');
    $start_date = $start_at !== '' ? substr($start_at, 0, 10) : '';
    return dcmt_reminder_normalize_recurrence_rule([
        'recurrence_type' => (string) ($row['dcmt_recurrence_type'] ?? 'none'),
        'recurrence_interval' => $row['dcmt_recurrence_interval'] ?? 1,
        'recurrence_weekdays' => $row['dcmt_recurrence_weekdays'] ?? [],
        'recurrence_monthly_mode' => (string) ($row['dcmt_recurrence_monthly_mode'] ?? 'day_of_month'),
        'recurrence_end_mode' => (string) ($row['dcmt_recurrence_end_mode'] ?? 'date'),
        'recurrence_end_date' => (string) ($row['dcmt_recurrence_end_date'] ?? ''),
        'recurrence_count' => $row['dcmt_recurrence_count'] ?? 0,
        'reminder_date' => $start_date,
    ]);
}

function dcmt_reminder_weekday_long(int $day): string
{
    return trans('reminder', 'weekday_long_' . $day);
}

function dcmt_reminder_weekday_short(int $day): string
{
    return trans('reminder', 'weekday_short_' . $day);
}

function dcmt_reminder_ordinal_label(int $nth, bool $is_last): string
{
    if ($is_last) {
        return trans('reminder', 'recurrence_ordinal_last');
    }
    $map = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth'];
    $key = $map[$nth] ?? 'last';
    return trans('reminder', 'recurrence_ordinal_' . $key);
}

/**
 * @param array<string,mixed> $rule
 */
function dcmt_reminder_format_recurrence_summary(array $rule, string $start_date = ''): string
{
    $type = $rule['type'] ?? 'none';
    if ($type === 'none' || $type === '') {
        return trans('reminder', 'recurrence_none');
    }

    $interval = max(1, (int) ($rule['interval'] ?? 1));
    $parts = [];
    if ($interval === 1) {
        $parts[] = trans('reminder', 'recurrence_' . $type);
    } else {
        $unit = trans('reminder', 'recurrence_unit_' . $type);
        $parts[] = str_replace(
            ['{interval}', '{unit}'],
            [(string) $interval, $unit],
            trans('reminder', 'recurrence_every_n')
        );
    }

    if ($type === 'weekly') {
        $weekdays = $rule['weekdays'] ?? [];
        $names = [];
        foreach ($weekdays as $day) {
            $names[] = dcmt_reminder_weekday_short((int) $day);
        }
        if (!empty($names)) {
            $parts[] = trans('reminder', 'recurrence_repeat_on') . ' ' . implode(', ', $names);
        }
    } elseif ($type === 'monthly' && $start_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        $ts = strtotime($start_date . ' 12:00:00');
        if ($ts !== false) {
            $day_num = (int) date('j', $ts);
            $dow = (int) date('w', $ts);
            $days_in_month = (int) date('t', $ts);
            $nth = (int) ceil($day_num / 7);
            $is_last = ($day_num + 7) > $days_in_month;
            if (($rule['monthly_mode'] ?? 'day_of_month') === 'nth_weekday') {
                $parts[] = str_replace(
                    ['{nth}', '{weekday}'],
                    [dcmt_reminder_ordinal_label($nth, $is_last), dcmt_reminder_weekday_long($dow)],
                    trans('reminder', 'recurrence_monthly_on_nth')
                );
            } else {
                $parts[] = str_replace('{day}', (string) $day_num, trans('reminder', 'recurrence_monthly_on_day'));
            }
        }
    }

    $end_mode = $rule['end_mode'] ?? 'date';
    if ($end_mode === 'never') {
        $parts[] = trans('reminder', 'recurrence_ends_never');
    } elseif ($end_mode === 'count') {
        $count = (int) ($rule['count'] ?? 0);
        $parts[] = str_replace('{count}', (string) $count, trans('reminder', 'recurrence_ends_after_n'));
    } elseif (!empty($rule['end_date'])) {
        $parts[] = trans('reminder', 'recurrence_end_date') . ': ' . (string) $rule['end_date'];
    }

    return implode(' · ', array_filter($parts));
}

function dcmt_reminder_shift_months(DateTime $start, int $months, int $day_of_month): DateTime
{
    $year = (int) $start->format('Y');
    $month = (int) $start->format('n') + $months;
    while ($month > 12) {
        $year++;
        $month -= 12;
    }
    while ($month < 1) {
        $year--;
        $month += 12;
    }
    $last_day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    $day = min($day_of_month, $last_day);
    $next = clone $start;
    $next->setDate($year, $month, $day);
    return $next;
}

function dcmt_reminder_nth_weekday_in_month(
    int $year,
    int $month,
    int $nth,
    int $weekday,
    bool $use_last,
    DateTimeZone $tz,
    DateTime $time_source
): ?DateTime {
    $hour = (int) $time_source->format('H');
    $minute = (int) $time_source->format('i');
    $second = (int) $time_source->format('s');
    $last_day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

    if ($use_last) {
        $dt = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $last_day), $tz);
        $dt->setTime($hour, $minute, $second);
        while ((int) $dt->format('w') !== $weekday) {
            $dt->modify('-1 day');
        }
        return $dt;
    }

    $first = new DateTime(sprintf('%04d-%02d-01', $year, $month), $tz);
    $delta = ($weekday - (int) $first->format('w') + 7) % 7;
    $day = 1 + $delta + (($nth - 1) * 7);
    if ($day > $last_day) {
        return null;
    }
    $dt = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day), $tz);
    $dt->setTime($hour, $minute, $second);
    return $dt;
}

/**
 * Occurrences after the start datetime (start itself is the parent reminder).
 *
 * @param array<string,mixed> $rule
 * @return array<int,DateTime>
 */
function dcmt_reminder_collect_recurrence_datetimes(DateTime $start, array $rule): array
{
    $type = $rule['type'] ?? 'none';
    if ($type === 'none') {
        return [];
    }

    $interval = max(1, (int) ($rule['interval'] ?? 1));
    $end_mode = $rule['end_mode'] ?? 'date';
    $end_date = trim((string) ($rule['end_date'] ?? ''));
    $count = (int) ($rule['count'] ?? 0);
    $max = (int) DCMT_REMINDER_RECURRENCE_MAX_INSTANCES;
    $needed = $max;
    if ($end_mode === 'count' && $count > 1) {
        $needed = min($max, $count - 1);
    }

    $out = [];
    $tz = $start->getTimezone();

    $max_allowed_date = dcmt_reminder_max_allowed_date();
    $is_past_end = static function (DateTime $candidate) use ($end_mode, $end_date, $max_allowed_date): bool {
        if ($candidate->format('Y-m-d') > $max_allowed_date) {
            return true;
        }
        return $end_mode === 'date' && $end_date !== '' && $candidate->format('Y-m-d') > $end_date;
    };

    if ($type === 'daily') {
        $current = clone $start;
        while (count($out) < $needed) {
            $current->modify('+' . $interval . ' days');
            if ($is_past_end($current)) {
                break;
            }
            $out[] = clone $current;
        }
        return $out;
    }

    if ($type === 'weekly') {
        $weekdays = $rule['weekdays'] ?? [];
        if (empty($weekdays)) {
            $weekdays = [(int) $start->format('w')];
        }
        sort($weekdays);
        $week0 = clone $start;
        $week0->modify('-' . (int) $start->format('w') . ' days');
        $week0->setTime(0, 0, 0);
        $week_offset = 0;
        $guard = 0;
        while (count($out) < $needed && $guard < 20000) {
            $guard++;
            foreach ($weekdays as $dow) {
                $candidate = clone $week0;
                $candidate->modify('+' . (($week_offset * 7) + (int) $dow) . ' days');
                $candidate->setTime(
                    (int) $start->format('H'),
                    (int) $start->format('i'),
                    (int) $start->format('s')
                );
                if ($candidate <= $start) {
                    continue;
                }
                if ($is_past_end($candidate)) {
                    return $out;
                }
                $out[] = $candidate;
                if (count($out) >= $needed) {
                    return $out;
                }
            }
            $week_offset += $interval;
        }
        return $out;
    }

    if ($type === 'monthly') {
        $mode = $rule['monthly_mode'] ?? 'day_of_month';
        $day_of_month = (int) $start->format('j');
        $nth = (int) ceil($day_of_month / 7);
        $is_last = ($day_of_month + 7) > (int) $start->format('t');
        $weekday = (int) $start->format('w');
        $n = 1;
        $guard = 0;
        while (count($out) < $needed && $guard < 2000) {
            $guard++;
            if ($mode === 'nth_weekday') {
                $year = (int) $start->format('Y');
                $month = (int) $start->format('n') + ($n * $interval);
                while ($month > 12) {
                    $year++;
                    $month -= 12;
                }
                $candidate = dcmt_reminder_nth_weekday_in_month(
                    $year,
                    $month,
                    $nth,
                    $weekday,
                    $is_last,
                    $tz,
                    $start
                );
                $n++;
                if ($candidate === null || $candidate <= $start) {
                    continue;
                }
            } else {
                $candidate = dcmt_reminder_shift_months($start, $n * $interval, $day_of_month);
                $n++;
                if ($candidate <= $start) {
                    continue;
                }
            }
            if ($is_past_end($candidate)) {
                break;
            }
            $out[] = $candidate;
        }
        return $out;
    }

    if ($type === 'yearly') {
        $month = (int) $start->format('n');
        $day = (int) $start->format('j');
        $n = 1;
        while (count($out) < $needed) {
            $year = (int) $start->format('Y') + ($n * $interval);
            $last_day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
            $candidate = clone $start;
            $candidate->setDate($year, $month, min($day, $last_day));
            $n++;
            if ($candidate <= $start) {
                continue;
            }
            if ($is_past_end($candidate)) {
                break;
            }
            $out[] = $candidate;
        }
    }

    return $out;
}

/**
 * @return array<int,int>
 */
function dcmt_reminder_fetch_assignee_ids(PDO $pdo, int $reminder_id): array
{
    if ($reminder_id <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT dcmt_user_id FROM dcmt_reminder_assignees WHERE dcmt_reminder_id = ? ORDER BY dcmt_id ASC');
    $stmt->execute([$reminder_id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return array<int,array<string,mixed>>
 */
function dcmt_reminder_fetch_assignees(PDO $pdo, int $reminder_id): array
{
    if ($reminder_id <= 0) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT u.dcmt_id, u.dcmt_full_name, u.dcmt_username, u.dcmt_role
        FROM dcmt_reminder_assignees a
        INNER JOIN dcmt_users u ON u.dcmt_id = a.dcmt_user_id
        WHERE a.dcmt_reminder_id = ?
        ORDER BY u.dcmt_full_name ASC, u.dcmt_username ASC
    ");
    $stmt->execute([$reminder_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function dcmt_reminder_sync_assignees(PDO $pdo, int $reminder_id, array $assignee_ids): void
{
    $assignee_ids = array_values(array_unique(array_filter(array_map('intval', $assignee_ids))));
    $pdo->prepare('DELETE FROM dcmt_reminder_assignees WHERE dcmt_reminder_id = ?')->execute([$reminder_id]);
    if (empty($assignee_ids)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO dcmt_reminder_assignees (dcmt_reminder_id, dcmt_user_id) VALUES (?, ?)');
    foreach ($assignee_ids as $user_id) {
        if ($user_id > 0) {
            $stmt->execute([$reminder_id, $user_id]);
        }
    }
}

/**
 * @return array<int,string>
 */
function dcmt_reminder_get_categories(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT DISTINCT dcmt_category
        FROM dcmt_reminders
        WHERE dcmt_category IS NOT NULL AND dcmt_category <> ''
        ORDER BY dcmt_category ASC
    ");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    return array_values(array_filter(array_map('strval', $rows)));
}

function dcmt_reminder_compute_end_at(string $start_at): string
{
    $dt = dcmt_reminder_parse_datetime_local($start_at);
    if (!$dt) {
        return $start_at;
    }
    $dt->modify('+' . (int) DCMT_REMINDER_CALENDAR_DURATION_MINUTES . ' minutes');
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Parse a stored reminder datetime in the app timezone.
 */
function dcmt_reminder_parse_datetime_local(string $value): ?DateTime
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $normalized = str_replace('T', ' ', substr($value, 0, 19));
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $normalized, $tz);
    if ($dt instanceof DateTime) {
        return $dt;
    }
    try {
        $dt = new DateTime($value, $tz);
        $dt->setTimezone($tz);
        return $dt;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * FullCalendar range bound (ISO with offset, MySQL datetime, or Y-m-d).
 */
function dcmt_reminder_parse_calendar_bound(string $value): ?DateTime
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    // Query-string "+" in a timezone offset becomes a space.
    if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?)[\s+](\d{2}:?\d{2})$/', $value, $m)) {
        $value = $m[1] . '+' . $m[2];
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value . ' 00:00:00', $tz);
        return $dt instanceof DateTime ? $dt : null;
    }
    try {
        $dt = new DateTime($value);
        $dt->setTimezone($tz);
        return $dt;
    } catch (Throwable $e) {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m)) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $m[1] . ' 00:00:00', $tz);
            return $dt instanceof DateTime ? $dt : null;
        }
        return null;
    }
}

function dcmt_reminder_iso_local(string $mysql_dt): string
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', trim($mysql_dt), $m)) {
        return $m[1] . 'T' . $m[2];
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', trim($mysql_dt), $m)) {
        return $m[1] . 'T00:00:00';
    }
    return str_replace(' ', 'T', substr(trim($mysql_dt), 0, 19));
}

function dcmt_reminder_status_color(string $status): string
{
    $map = [
        'pending' => '#fd7e14',
        'completed' => '#6c757d',
        'cancelled' => '#dc3545',
    ];
    return $map[$status] ?? '#0d6efd';
}

function dcmt_reminder_priority_color(string $priority, string $status = 'pending'): string
{
    if ($status === 'completed') {
        return '#6c757d';
    }
    if ($status === 'cancelled') {
        return '#dc3545';
    }
    $map = [
        'high' => '#dc3545',
        'medium' => '#fd7e14',
        'low' => '#198754',
    ];
    return $map[$priority] ?? '#0d6efd';
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $rule
 * @param array<int,int> $assignee_ids
 */
function dcmt_reminder_generate_recurring_instances(
    PDO $pdo,
    int $parent_id,
    string $start_at,
    array $rule,
    array $payload,
    array $current_user,
    array $assignee_ids
): void {
    $tz = new DateTimeZone(date_default_timezone_get());
    $start = DateTime::createFromFormat('Y-m-d H:i:s', $start_at, $tz);
    if (!$start) {
        return;
    }

    $occurrences = dcmt_reminder_collect_recurrence_datetimes($start, $rule);
    if (empty($occurrences)) {
        return;
    }

    $title = trim((string) ($payload['title'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $description = $description !== '' ? $description : null;
    $priority = dcmt_reminder_normalize_priority($payload['priority'] ?? 'medium');
    $category = trim((string) ($payload['category'] ?? ''));
    $category = $category !== '' ? $category : null;
    $creator_id = (int) ($current_user['dcmt_id'] ?? 0);
    $created_by = (string) ($current_user['dcmt_username'] ?? 'system');
    $assigned_user_id = $assignee_ids[0] ?? 0;
    $recurrence_type = $rule['type'] ?? 'none';
    $recurrence_interval = max(1, (int) ($rule['interval'] ?? 1));
    $recurrence_weekdays = ($recurrence_type === 'weekly' && !empty($rule['weekdays']))
        ? implode(',', $rule['weekdays'])
        : null;
    $recurrence_monthly_mode = $recurrence_type === 'monthly' ? ($rule['monthly_mode'] ?? 'day_of_month') : null;
    $recurrence_end_mode = $rule['end_mode'] ?? 'date';
    $recurrence_count = $recurrence_end_mode === 'count' ? (int) ($rule['count'] ?? 0) : null;
    $recurrence_end_date = $recurrence_end_mode === 'date' ? ($rule['end_date'] ?? null) : null;
    if ($recurrence_end_date === '') {
        $recurrence_end_date = null;
    }

    $insert = $pdo->prepare("
        INSERT INTO dcmt_reminders (
            dcmt_assigned_user_id,
            dcmt_title,
            dcmt_description,
            dcmt_priority,
            dcmt_category,
            dcmt_recurrence_type,
            dcmt_recurrence_interval,
            dcmt_recurrence_weekdays,
            dcmt_recurrence_monthly_mode,
            dcmt_recurrence_end_mode,
            dcmt_recurrence_count,
            dcmt_recurrence_end_date,
            dcmt_parent_reminder_id,
            dcmt_is_recurring,
            dcmt_reminder_at,
            dcmt_notify_at,
            dcmt_status,
            dcmt_created_by_user_id,
            dcmt_created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'pending', ?, ?)
    ");

    foreach ($occurrences as $occurrence) {
        $reminder_at = $occurrence->format('Y-m-d H:i:s');
        $notify_at = dcmt_reminder_compute_notify_at($reminder_at);
        $insert->execute([
            $assigned_user_id,
            $title,
            $description,
            $priority,
            $category,
            $recurrence_type,
            $recurrence_interval,
            $recurrence_weekdays,
            $recurrence_monthly_mode,
            $recurrence_end_mode,
            $recurrence_count,
            $recurrence_end_date,
            $parent_id,
            $reminder_at,
            $notify_at,
            $creator_id,
            $created_by,
        ]);
        $child_id = (int) $pdo->lastInsertId();
        dcmt_reminder_sync_assignees($pdo, $child_id, $assignee_ids);
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function dcmt_reminder_fetch_calendar_events(
    PDO $pdo,
    ?array $user,
    string $start,
    string $end,
    int $assignee_filter = 0,
    string $status_filter = ''
): array {
    $start_dt = dcmt_reminder_parse_calendar_bound($start);
    $end_dt = dcmt_reminder_parse_calendar_bound($end);
    if (!$start_dt || !$end_dt) {
        return [];
    }
    if ($end_dt <= $start_dt) {
        $end_dt = (clone $start_dt)->modify('+1 month');
    }

    $where = ['r.dcmt_reminder_at >= ?', 'r.dcmt_reminder_at < ?'];
    $params = [$start_dt->format('Y-m-d H:i:s'), $end_dt->format('Y-m-d H:i:s')];

    list($scope_sql, $scope_params) = dcmt_reminder_list_scope_sql($user, 'r');
    $where[] = $scope_sql;
    $params = array_merge($params, $scope_params);

    if ($assignee_filter > 0) {
        $where[] = 'r.dcmt_id IN (SELECT dcmt_reminder_id FROM dcmt_reminder_assignees WHERE dcmt_user_id = ?)';
        $params[] = $assignee_filter;
    }

    if (in_array($status_filter, ['pending', 'completed', 'cancelled'], true)) {
        $where[] = 'r.dcmt_status = ?';
        $params[] = $status_filter;
    }

    $sql = "
        SELECT r.dcmt_id, r.dcmt_title, r.dcmt_description, r.dcmt_reminder_at, r.dcmt_status,
               r.dcmt_priority, r.dcmt_category, r.dcmt_is_recurring, r.dcmt_recurrence_type,
               u.dcmt_full_name AS assigned_user_name
        FROM dcmt_reminders r
        LEFT JOIN dcmt_users u ON u.dcmt_id = r.dcmt_assigned_user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.dcmt_reminder_at ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reminder_ids = array_column($rows, 'dcmt_id');
    $assignee_map = [];
    if (!empty($reminder_ids)) {
        try {
            $ph = implode(',', array_fill(0, count($reminder_ids), '?'));
            $a_stmt = $pdo->prepare("
                SELECT a.dcmt_reminder_id, u.dcmt_full_name
                FROM dcmt_reminder_assignees a
                LEFT JOIN dcmt_users u ON u.dcmt_id = a.dcmt_user_id
                WHERE a.dcmt_reminder_id IN ({$ph})
            ");
            $a_stmt->execute($reminder_ids);
            foreach ($a_stmt->fetchAll(PDO::FETCH_ASSOC) as $a_row) {
                $assignee_map[(int) $a_row['dcmt_reminder_id']][] = $a_row['dcmt_full_name'];
            }
        } catch (Throwable $e) {
            error_log('Reminder calendar assignee lookup failed: ' . $e->getMessage());
        }
    }

    $events = [];
    foreach ($rows as $row) {
        try {
            $id = (int) ($row['dcmt_id'] ?? 0);
            $start_at = (string) ($row['dcmt_reminder_at'] ?? '');
            if ($start_at === '' || $id <= 0) {
                continue;
            }
            $status = (string) ($row['dcmt_status'] ?? 'pending');
            if (!in_array($status, ['pending', 'completed', 'cancelled'], true)) {
                $status = 'pending';
            }
            $priority = (string) ($row['dcmt_priority'] ?? 'medium');
            $assignees = $assignee_map[$id] ?? [];
            if (empty($assignees) && !empty($row['assigned_user_name'])) {
                $assignees = [(string) $row['assigned_user_name']];
            }
            $color = dcmt_reminder_priority_color($priority, $status);
            $end_at = dcmt_reminder_compute_end_at($start_at);

            $events[] = [
                'id' => (string) $id,
                'title' => (string) ($row['dcmt_title'] ?? ''),
                'start' => dcmt_reminder_iso_local($start_at),
                'end' => dcmt_reminder_iso_local($end_at),
                'allDay' => false,
                'backgroundColor' => $color,
                'borderColor' => $color,
                'status' => $status,
                'extendedProps' => [
                    'status' => $status,
                    'priority' => $priority,
                    'category' => (string) ($row['dcmt_category'] ?? ''),
                    'assignees' => implode(', ', array_filter($assignees)),
                    'description' => (string) ($row['dcmt_description'] ?? ''),
                    'is_recurring' => !empty($row['dcmt_is_recurring']),
                    'recurrence_type' => (string) ($row['dcmt_recurrence_type'] ?? 'none'),
                    'google_calendar_url' => dcmt_reminder_build_google_calendar_url([
                        'dcmt_title' => (string) ($row['dcmt_title'] ?? ''),
                        'dcmt_description' => (string) ($row['dcmt_description'] ?? ''),
                        'dcmt_reminder_at' => $start_at,
                        '_assignees' => array_map(static function (string $name): array {
                            return ['dcmt_full_name' => $name];
                        }, array_values(array_filter($assignees))),
                    ]),
                ],
            ];
        } catch (Throwable $e) {
            error_log('Reminder calendar event skipped: ' . $e->getMessage());
        }
    }

    return $events;
}

function dcmt_reminder_build_google_calendar_url(array $reminder): string
{
    $tz = date_default_timezone_get();
    $start_at = (string) ($reminder['dcmt_reminder_at'] ?? '');
    $end_at = dcmt_reminder_compute_end_at($start_at);

    $toGoogle = static function (string $datetime) use ($tz): string {
        $dt = dcmt_reminder_parse_datetime_local($datetime);
        if (!$dt) {
            return '';
        }
        return $dt->format('Ymd\THis');
    };

    $start = $toGoogle($start_at);
    $end = $toGoogle($end_at);
    if ($start === '' || $end === '') {
        return '#';
    }

    $title = (string) ($reminder['dcmt_title'] ?? trans('reminder', 'reminder'));
    $details = (string) ($reminder['dcmt_description'] ?? '');
    $assignees = [];
    foreach ($reminder['_assignees'] ?? [] as $assignee) {
        $name = trim((string) ($assignee['dcmt_full_name'] ?? $assignee['dcmt_username'] ?? ''));
        if ($name !== '') {
            $assignees[] = $name;
        }
    }
    if (!empty($assignees)) {
        $details = trim($details . "\n" . trans('reminder', 'assigned_to') . ': ' . implode(', ', $assignees));
    }

    return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . rawurlencode($title)
        . '&dates=' . rawurlencode($start . '/' . $end)
        . '&details=' . rawurlencode($details)
        . '&ctz=' . rawurlencode($tz);
}
