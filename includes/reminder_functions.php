<?php
/**
 * Reminder module service functions
 * Dental Clinic Management System
 */

if (!defined('DCMT_REMINDER_ADVANCE_HOURS')) {
    define('DCMT_REMINDER_ADVANCE_HOURS', 2);
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

    $assigned_user_id = (int) ($data['assigned_user_id'] ?? 0);
    if ($assigned_user_id <= 0) {
        $errors[] = trans('reminder', 'assigned_user_required');
    }

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
    return $uid === (int) ($reminder['dcmt_assigned_user_id'] ?? 0)
        || $uid === (int) ($reminder['dcmt_created_by_user_id'] ?? 0);
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
        "({$alias}.dcmt_assigned_user_id = ? OR {$alias}.dcmt_created_by_user_id = ?)",
        [$uid, $uid],
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
    return $row ?: null;
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
        SELECT r.dcmt_id,
               r.dcmt_title,
               r.dcmt_description,
               r.dcmt_reminder_at,
               r.dcmt_notify_at,
               r.dcmt_notification_sent_at
        FROM dcmt_reminders r
        WHERE r.dcmt_assigned_user_id = ?
          AND r.dcmt_status = 'pending'
          AND r.dcmt_notification_sent = 1
          AND r.dcmt_notification_dismissed = 0
        ORDER BY r.dcmt_reminder_at ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dcmt_reminder_count_active_notifications(PDO $pdo, int $user_id): int
{
    if ($user_id <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM dcmt_reminders
        WHERE dcmt_assigned_user_id = ?
          AND dcmt_status = 'pending'
          AND dcmt_notification_sent = 1
          AND dcmt_notification_dismissed = 0
    ");
    $stmt->execute([$user_id]);
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

    $assigned_user_id = (int) $payload['assigned_user_id'];

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

    $creator_id = (int) ($current_user['dcmt_id'] ?? 0);
    $created_by = (string) ($current_user['dcmt_username'] ?? 'system');

    $stmt = $pdo->prepare("
        INSERT INTO dcmt_reminders (
            dcmt_assigned_user_id,
            dcmt_title,
            dcmt_description,
            dcmt_reminder_at,
            dcmt_notify_at,
            dcmt_status,
            dcmt_created_by_user_id,
            dcmt_created_by
        ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)
    ");
    $stmt->execute([
        $assigned_user_id,
        $title,
        $description,
        $reminder_at,
        $notify_at,
        $creator_id,
        $created_by,
    ]);

    return ['success' => true, 'id' => (int) $pdo->lastInsertId()];
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

    $assigned_user_id = (int) $payload['assigned_user_id'];

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

    $reset_notification = (
        ($existing['dcmt_reminder_at'] ?? '') !== $reminder_at
        || (int) ($existing['dcmt_assigned_user_id'] ?? 0) !== $assigned_user_id
    );

    if ($reset_notification) {
        $stmt = $pdo->prepare("
            UPDATE dcmt_reminders
            SET dcmt_assigned_user_id = ?,
                dcmt_title = ?,
                dcmt_description = ?,
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
                dcmt_reminder_at = ?,
                dcmt_notify_at = ?,
                dcmt_updated_at = CURRENT_TIMESTAMP
            WHERE dcmt_id = ?
        ");
        $stmt->execute([
            $assigned_user_id,
            $title,
            $description,
            $reminder_at,
            $notify_at,
            $reminder_id,
        ]);
    }

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
    if ((int) ($existing['dcmt_assigned_user_id'] ?? 0) !== $uid && !dcmt_reminder_user_is_admin($current_user)) {
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
