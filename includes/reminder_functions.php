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

    $recurrence_type = trim((string) ($data['recurrence_type'] ?? 'none'));
    if (!in_array($recurrence_type, ['none', 'daily', 'weekly', 'monthly'], true)) {
        $errors[] = trans('reminder', 'invalid_recurrence');
    }

    $recurrence_end_date = trim((string) ($data['recurrence_end_date'] ?? ''));
    if ($recurrence_type !== 'none') {
        if ($recurrence_end_date === '') {
            $errors[] = trans('reminder', 'recurrence_end_required');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recurrence_end_date)) {
            $errors[] = trans('reminder', 'invalid_recurrence');
        } elseif ($reminder_date !== '' && $recurrence_end_date < $reminder_date) {
            $errors[] = trans('reminder', 'recurrence_end_after_start');
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
    $recurrence_type = dcmt_reminder_normalize_recurrence_type($payload['recurrence_type'] ?? 'none');
    $recurrence_end_date = trim((string) ($payload['recurrence_end_date'] ?? ''));
    $recurrence_end_date = $recurrence_type !== 'none' ? $recurrence_end_date : null;
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
                dcmt_recurrence_end_date,
                dcmt_is_recurring,
                dcmt_reminder_at,
                dcmt_notify_at,
                dcmt_status,
                dcmt_created_by_user_id,
                dcmt_created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ");
        $stmt->execute([
            $assigned_user_id,
            $title,
            $description,
            $priority,
            $category,
            $recurrence_type,
            $recurrence_end_date,
            $is_recurring,
            $reminder_at,
            $notify_at,
            $creator_id,
            $created_by,
        ]);

        $reminder_id = (int) $pdo->lastInsertId();
        dcmt_reminder_sync_assignees($pdo, $reminder_id, $assignee_ids);

        if ($recurrence_type !== 'none' && $recurrence_end_date !== null) {
            dcmt_reminder_generate_recurring_instances(
                $pdo,
                $reminder_id,
                $reminder_at,
                $recurrence_type,
                $recurrence_end_date,
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
    return in_array($type, ['none', 'daily', 'weekly', 'monthly'], true) ? $type : 'none';
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
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $start_at, $tz);
    if (!$dt) {
        $dt = new DateTime($start_at, $tz);
    }
    $dt->modify('+' . (int) DCMT_REMINDER_CALENDAR_DURATION_MINUTES . ' minutes');
    return $dt->format('Y-m-d H:i:s');
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
 * @param array<int,int> $assignee_ids
 */
function dcmt_reminder_generate_recurring_instances(
    PDO $pdo,
    int $parent_id,
    string $start_at,
    string $recurrence_type,
    string $recurrence_end_date,
    array $payload,
    array $current_user,
    array $assignee_ids
): void {
    $tz = new DateTimeZone(date_default_timezone_get());
    $current = DateTime::createFromFormat('Y-m-d H:i:s', $start_at, $tz);
    $end_date = DateTime::createFromFormat('Y-m-d', $recurrence_end_date, $tz);
    if (!$current || !$end_date) {
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

    $insert = $pdo->prepare("
        INSERT INTO dcmt_reminders (
            dcmt_assigned_user_id,
            dcmt_title,
            dcmt_description,
            dcmt_priority,
            dcmt_category,
            dcmt_recurrence_type,
            dcmt_recurrence_end_date,
            dcmt_parent_reminder_id,
            dcmt_is_recurring,
            dcmt_reminder_at,
            dcmt_notify_at,
            dcmt_status,
            dcmt_created_by_user_id,
            dcmt_created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'pending', ?, ?)
    ");

    $count = 0;
    while ($count < (int) DCMT_REMINDER_RECURRENCE_MAX_INSTANCES) {
        if ($recurrence_type === 'daily') {
            $current->modify('+1 day');
        } elseif ($recurrence_type === 'weekly') {
            $current->modify('+1 week');
        } else {
            $current->modify('+1 month');
        }

        if ($current->format('Y-m-d') > $end_date->format('Y-m-d')) {
            break;
        }

        $reminder_at = $current->format('Y-m-d H:i:s');
        $notify_at = dcmt_reminder_compute_notify_at($reminder_at);
        $insert->execute([
            $assigned_user_id,
            $title,
            $description,
            $priority,
            $category,
            $recurrence_type,
            $recurrence_end_date,
            $parent_id,
            $reminder_at,
            $notify_at,
            $creator_id,
            $created_by,
        ]);
        $child_id = (int) $pdo->lastInsertId();
        dcmt_reminder_sync_assignees($pdo, $child_id, $assignee_ids);
        $count++;
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
    $where = ['r.dcmt_reminder_at >= ?', 'r.dcmt_reminder_at < ?'];
    $params = [$start . ' 00:00:00', $end . ' 23:59:59'];

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
    }

    $events = [];
    foreach ($rows as $row) {
        $id = (int) ($row['dcmt_id'] ?? 0);
        $start_at = (string) ($row['dcmt_reminder_at'] ?? '');
        $status = (string) ($row['dcmt_status'] ?? 'pending');
        $priority = (string) ($row['dcmt_priority'] ?? 'medium');
        $assignees = $assignee_map[$id] ?? [];
        if (empty($assignees) && !empty($row['assigned_user_name'])) {
            $assignees = [(string) $row['assigned_user_name']];
        }

        $events[] = [
            'id' => $id,
            'title' => (string) ($row['dcmt_title'] ?? ''),
            'start' => $start_at,
            'end' => dcmt_reminder_compute_end_at($start_at),
            'backgroundColor' => dcmt_reminder_priority_color($priority, $status),
            'borderColor' => dcmt_reminder_priority_color($priority, $status),
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
    }

    return $events;
}

function dcmt_reminder_build_google_calendar_url(array $reminder): string
{
    $tz = date_default_timezone_get();
    $start_at = (string) ($reminder['dcmt_reminder_at'] ?? '');
    $end_at = dcmt_reminder_compute_end_at($start_at);

    $toGoogle = static function (string $datetime) use ($tz): string {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime, new DateTimeZone($tz));
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
