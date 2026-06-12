<?php
/**
 * Internal messaging service functions
 */

function dcmt_messaging_allowed_roles(): array
{
    return ['admin', 'staff', 'doctor', 'assistant'];
}

function dcmt_messaging_role_display_label(string $role): string
{
    $role = strtolower(trim($role));
    $key = 'role_' . $role;
    $label = trans('messaging', $key, '');
    if ($label !== '' && $label !== $key) {
        return $label;
    }

    return ucfirst($role);
}

function dcmt_messaging_user_display_name(string $full_name, string $role): string
{
    $full_name = trim($full_name);
    $label = dcmt_messaging_role_display_label($role);
    if ($full_name === '') {
        return $label;
    }

    return $full_name . ' (' . $label . ')';
}

function dcmt_messaging_user_can_access(array $user): bool
{
    return ($user['dcmt_status'] ?? '') === 'active'
        && in_array((string) ($user['dcmt_role'] ?? ''), dcmt_messaging_allowed_roles(), true);
}

function dcmt_messaging_can_message_user(array $from_user, int $to_user_id, PDO $pdo): bool
{
    if ($to_user_id <= 0 || (int) ($from_user['dcmt_id'] ?? 0) === $to_user_id) {
        return false;
    }
    if (!dcmt_messaging_user_can_access($from_user)) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT dcmt_role, dcmt_status FROM dcmt_users WHERE dcmt_id = ? LIMIT 1");
    $stmt->execute([$to_user_id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target || ($target['dcmt_status'] ?? '') !== 'active') {
        return false;
    }
    $from_role = (string) ($from_user['dcmt_role'] ?? '');
    $to_role = (string) ($target['dcmt_role'] ?? '');
    if (dcmt_is_admin()) {
        return in_array($to_role, dcmt_messaging_allowed_roles(), true);
    }
    if ($from_role === 'doctor') {
        return in_array($to_role, ['staff', 'assistant', 'admin', 'doctor'], true);
    }
    if ($from_role === 'staff') {
        return in_array($to_role, ['doctor', 'assistant', 'admin', 'staff'], true);
    }
    if ($from_role === 'assistant') {
        return in_array($to_role, ['doctor', 'staff', 'assistant'], true);
    }
    return false;
}

function dcmt_messaging_direct_key(int $a, int $b): string
{
    return 'direct:' . min($a, $b) . ':' . max($a, $b);
}

function dcmt_messaging_is_participant(PDO $pdo, int $conversation_id, int $user_id): bool
{
    $stmt = $pdo->prepare("
        SELECT 1 FROM dcmt_conversation_participants
        WHERE dcmt_conversation_id = ? AND dcmt_user_id = ? AND dcmt_left_at IS NULL LIMIT 1
    ");
    $stmt->execute([$conversation_id, $user_id]);
    return (bool) $stmt->fetchColumn();
}

function dcmt_messaging_format_time_display(string $datetime): string
{
    if ($datetime === '') {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }
    if (date('Y-m-d', $ts) === dcmt_get_current_date('Y-m-d')) {
        return date('g:i A', $ts);
    }
    return date('M j, g:i A', $ts);
}

function dcmt_messaging_format_message_row(array $row, int $viewer_user_id): array
{
    $sender_id = (int) ($row['dcmt_sender_user_id'] ?? 0);
    $message_type = (string) ($row['dcmt_message_type'] ?? 'text');
    if ($message_type === 'appointment') {
        $sender_label = trans('messaging', 'clinic_notifications');
    } elseif ($sender_id > 0) {
        $sender_label = (string) ($row['sender_name'] ?? '');
    } else {
        $sender_label = trans('messaging', 'system_sender');
    }
    return [
        'id' => (int) $row['dcmt_id'],
        'conversation_id' => (int) $row['dcmt_conversation_id'],
        'sender_user_id' => $sender_id,
        'sender_name' => $sender_label,
        'body' => (string) $row['dcmt_body'],
        'message_type' => $message_type,
        'priority' => (string) ($row['dcmt_priority'] ?? 'normal'),
        'is_mine' => $sender_id === $viewer_user_id,
        'created_at_display' => dcmt_messaging_format_time_display((string) ($row['dcmt_created_at'] ?? '')),
    ];
}

function dcmt_messaging_insert_participants(PDO $pdo, int $conversation_id, array $user_ids, int $owner_id): void
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO dcmt_conversation_participants
        (dcmt_conversation_id, dcmt_user_id, dcmt_role_in_conversation, dcmt_joined_at)
        VALUES (?, ?, ?, NOW())
    ");
    foreach (array_unique($user_ids) as $uid) {
        $uid = (int) $uid;
        if ($uid > 0) {
            $stmt->execute([$conversation_id, $uid, $uid === $owner_id ? 'owner' : 'member']);
        }
    }
}

function dcmt_messaging_get_or_create_direct(PDO $pdo, array $current_user, int $other_user_id): array
{
    if (!dcmt_messaging_can_message_user($current_user, $other_user_id, $pdo)) {
        return ['success' => false, 'errors' => [trans('messaging', 'cannot_message_user')]];
    }
    $user_id = (int) $current_user['dcmt_id'];
    $key = dcmt_messaging_direct_key($user_id, $other_user_id);
    $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_conversations WHERE dcmt_direct_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $cid = (int) $stmt->fetchColumn();
    if ($cid > 0) {
        dcmt_messaging_insert_participants($pdo, $cid, [$user_id, $other_user_id], $user_id);
        return ['success' => true, 'conversation_id' => $cid];
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO dcmt_conversations (dcmt_type, dcmt_direct_key, dcmt_created_by_user_id)
            VALUES ('direct', ?, ?)
        ")->execute([$key, $user_id]);
        $cid = (int) $pdo->lastInsertId();
        dcmt_messaging_insert_participants($pdo, $cid, [$user_id, $other_user_id], $user_id);
        $pdo->commit();
        return ['success' => true, 'conversation_id' => $cid];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('messaging get_or_create_direct: ' . $e->getMessage());
        return ['success' => false, 'errors' => [trans('messaging', 'database_error')]];
    }
}

function dcmt_messaging_send_message(
    PDO $pdo,
    array $current_user,
    int $conversation_id,
    string $body,
    string $priority = 'normal',
    ?string $idempotency_key = null,
    string $message_type = 'text'
): array {
    $user_id = (int) $current_user['dcmt_id'];
    $body = trim($body);
    if ($conversation_id <= 0 || !dcmt_messaging_is_participant($pdo, $conversation_id, $user_id)) {
        return ['success' => false, 'errors' => [trans('messaging', 'access_denied')]];
    }
    if ($body === '') {
        return ['success' => false, 'errors' => [trans('messaging', 'message_required')]];
    }
    if (strlen($body) > 4000) {
        return ['success' => false, 'errors' => [trans('messaging', 'message_too_long')]];
    }

    $idempotency_key = $idempotency_key !== null ? trim($idempotency_key) : '';
    if ($idempotency_key !== '') {
        $dup = $pdo->prepare("
            SELECT m.*, u.dcmt_full_name AS sender_name FROM dcmt_messages m
            LEFT JOIN dcmt_users u ON u.dcmt_id = m.dcmt_sender_user_id
            WHERE m.dcmt_idempotency_key = ? LIMIT 1
        ");
        $dup->execute([$idempotency_key]);
        $existing = $dup->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return [
                'success' => true,
                'message' => dcmt_messaging_format_message_row($existing, $user_id),
                'duplicate' => true,
            ];
        }
    }

    $allowed_types = ['text', 'system', 'appointment', 'task', 'priority'];
    if (!in_array($message_type, $allowed_types, true)) {
        $message_type = 'text';
    }
    $msg_type = $priority === 'high' ? 'priority' : $message_type;

    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO dcmt_messages
            (dcmt_conversation_id, dcmt_sender_user_id, dcmt_message_type, dcmt_body, dcmt_priority, dcmt_idempotency_key, dcmt_created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $conversation_id,
            $user_id,
            $msg_type,
            $body,
            $priority,
            $idempotency_key !== '' ? $idempotency_key : null,
        ]);
        $mid = (int) $pdo->lastInsertId();
        $now = dcmt_get_current_datetime('Y-m-d H:i:s');
        $pdo->prepare("
            UPDATE dcmt_conversations SET dcmt_last_message_id = ?, dcmt_last_message_at = ? WHERE dcmt_id = ?
        ")->execute([$mid, $now, $conversation_id]);
        $pdo->prepare("
            UPDATE dcmt_conversation_participants SET dcmt_unread_count = dcmt_unread_count + 1
            WHERE dcmt_conversation_id = ? AND dcmt_user_id != ? AND dcmt_left_at IS NULL
        ")->execute([$conversation_id, $user_id]);
        $pdo->prepare("
            UPDATE dcmt_conversation_participants
            SET dcmt_last_read_message_id = ?, dcmt_last_read_at = ?, dcmt_unread_count = 0
            WHERE dcmt_conversation_id = ? AND dcmt_user_id = ?
        ")->execute([$mid, $now, $conversation_id, $user_id]);
        $pdo->commit();

        $stmt = $pdo->prepare("
            SELECT m.*, u.dcmt_full_name AS sender_name FROM dcmt_messages m
            LEFT JOIN dcmt_users u ON u.dcmt_id = m.dcmt_sender_user_id WHERE m.dcmt_id = ?
        ");
        $stmt->execute([$mid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['success' => true, 'message' => dcmt_messaging_format_message_row($row ?: [], $user_id)];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'errors' => [trans('messaging', 'database_error')]];
    }
}

function dcmt_messaging_list_conversations(PDO $pdo, int $user_id, string $search = '', int $limit = 40): array
{
    $params = [$user_id, $user_id];
    $search_sql = '';
    if ($search !== '') {
        $search_sql = " AND (
            c.dcmt_title LIKE ?
            OR ou.dcmt_full_name LIKE ?
            OR ou.dcmt_username LIKE ?
            OR lm.dcmt_body LIKE ?
        )";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $params[] = $limit;
    $stmt = $pdo->prepare("
        SELECT c.dcmt_id, c.dcmt_type, c.dcmt_title, c.dcmt_last_message_at, p.dcmt_unread_count,
               lm.dcmt_body AS last_message_body, ou.dcmt_id AS other_user_id,
               ou.dcmt_full_name AS other_user_name, ou.dcmt_role AS other_user_role
        FROM dcmt_conversation_participants p
        INNER JOIN dcmt_conversations c ON c.dcmt_id = p.dcmt_conversation_id
        LEFT JOIN dcmt_messages lm ON lm.dcmt_id = c.dcmt_last_message_id
        LEFT JOIN dcmt_conversation_participants op ON op.dcmt_conversation_id = c.dcmt_id
            AND op.dcmt_user_id != ? AND op.dcmt_left_at IS NULL AND c.dcmt_type = 'direct'
        LEFT JOIN dcmt_users ou ON ou.dcmt_id = op.dcmt_user_id
        WHERE p.dcmt_user_id = ? AND p.dcmt_left_at IS NULL AND c.dcmt_is_archived = 0
        {$search_sql}
        ORDER BY COALESCE(c.dcmt_last_message_at, c.dcmt_created_at) DESC
        LIMIT ?
    ");
    $stmt->execute($params);
    $out = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $conv_id = (int) ($row['dcmt_id'] ?? 0);
        if ($conv_id <= 0 || isset($seen[$conv_id])) {
            continue;
        }
        $seen[$conv_id] = true;
        $title = trim((string) ($row['dcmt_title'] ?? ''));
        if ($title === '' && ($row['dcmt_type'] ?? '') === 'direct') {
            $title = (string) ($row['other_user_name'] ?? trans('messaging', 'conversation'));
        }
        $preview = (string) ($row['last_message_body'] ?? '');
        if (strlen($preview) > 60) {
            $preview = substr($preview, 0, 57) . '...';
        }

        $full_name = '';
        $role_label = '';
        $display_name = $title;
        if (($row['dcmt_type'] ?? '') === 'direct') {
            $full_name = trim((string) ($row['other_user_name'] ?? ''));
            if ($full_name !== '') {
                $role = (string) ($row['other_user_role'] ?? '');
                $role_label = dcmt_messaging_role_display_label($role);
                $display_name = dcmt_messaging_user_display_name($full_name, $role);
                if ($title === '' || $title === $full_name) {
                    $title = $display_name;
                }
            }
        }

        $out[] = [
            'id' => $conv_id,
            'type' => (string) $row['dcmt_type'],
            'title' => $title,
            'full_name' => $full_name,
            'display_name' => $display_name,
            'role_label' => $role_label,
            'other_user_id' => (int) ($row['other_user_id'] ?? 0),
            'unread_count' => (int) $row['dcmt_unread_count'],
            'last_message_at_display' => dcmt_messaging_format_time_display((string) ($row['dcmt_last_message_at'] ?? '')),
            'preview' => $preview,
        ];
    }
    return $out;
}

function dcmt_messaging_get_thread_messages(PDO $pdo, int $conversation_id, int $user_id, int $before_id = 0, int $limit = 40): array
{
    if (!dcmt_messaging_is_participant($pdo, $conversation_id, $user_id)) {
        return [];
    }
    $params = [$conversation_id];
    $before_sql = $before_id > 0 ? ' AND m.dcmt_id < ?' : '';
    if ($before_id > 0) {
        $params[] = $before_id;
    }
    $params[] = $limit;
    $stmt = $pdo->prepare("
        SELECT m.*, u.dcmt_full_name AS sender_name FROM dcmt_messages m
        LEFT JOIN dcmt_users u ON u.dcmt_id = m.dcmt_sender_user_id
        WHERE m.dcmt_conversation_id = ? AND m.dcmt_deleted_at IS NULL {$before_sql}
        ORDER BY m.dcmt_id DESC LIMIT ?
    ");
    $stmt->execute($params);
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    return array_map(fn($r) => dcmt_messaging_format_message_row($r, $user_id), $rows);
}

function dcmt_messaging_poll_new_messages(PDO $pdo, int $conversation_id, int $user_id, int $after_id): array
{
    if (!dcmt_messaging_is_participant($pdo, $conversation_id, $user_id)) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT m.*, u.dcmt_full_name AS sender_name FROM dcmt_messages m
        LEFT JOIN dcmt_users u ON u.dcmt_id = m.dcmt_sender_user_id
        WHERE m.dcmt_conversation_id = ? AND m.dcmt_deleted_at IS NULL AND m.dcmt_id > ?
        ORDER BY m.dcmt_id ASC LIMIT 50
    ");
    $stmt->execute([$conversation_id, $after_id]);
    return array_map(fn($r) => dcmt_messaging_format_message_row($r, $user_id), $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function dcmt_messaging_mark_read(PDO $pdo, int $conversation_id, int $user_id): void
{
    if (!dcmt_messaging_is_participant($pdo, $conversation_id, $user_id)) {
        return;
    }
    $mid_stmt = $pdo->prepare("
        SELECT MAX(dcmt_id) FROM dcmt_messages
        WHERE dcmt_conversation_id = ? AND dcmt_deleted_at IS NULL
    ");
    $mid_stmt->execute([$conversation_id]);
    $mid = (int) $mid_stmt->fetchColumn();
    $now = dcmt_get_current_datetime('Y-m-d H:i:s');
    $pdo->prepare("
        UPDATE dcmt_conversation_participants
        SET dcmt_last_read_message_id = ?, dcmt_last_read_at = ?, dcmt_unread_count = 0
        WHERE dcmt_conversation_id = ? AND dcmt_user_id = ?
    ")->execute([$mid ?: null, $now, $conversation_id, $user_id]);
}

function dcmt_messaging_count_unread_total(PDO $pdo, int $user_id): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(dcmt_unread_count), 0) FROM dcmt_conversation_participants
        WHERE dcmt_user_id = ? AND dcmt_left_at IS NULL
    ");
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

function dcmt_messaging_search_users(PDO $pdo, array $current_user, string $q, int $limit = 25): array
{
    $user_id = (int) $current_user['dcmt_id'];
    $q = trim($q);
    $roles = dcmt_messaging_allowed_roles();
    $ph = implode(',', array_fill(0, count($roles), '?'));

    $params = [$user_id];
    foreach ($roles as $r) {
        $params[] = $r;
    }

    $search_sql = '';
    if ($q !== '') {
        $search_sql = ' AND (dcmt_full_name LIKE ? OR dcmt_username LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $params[] = $limit;

    $stmt = $pdo->prepare("
        SELECT dcmt_id, dcmt_full_name, dcmt_username, dcmt_role FROM dcmt_users
        WHERE dcmt_id != ? AND dcmt_status = 'active' AND dcmt_role IN ({$ph}) {$search_sql}
        ORDER BY dcmt_full_name ASC LIMIT ?
    ");
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = (int) $row['dcmt_id'];
        if (!dcmt_messaging_can_message_user($current_user, $uid, $pdo)) {
            continue;
        }
        $role = (string) $row['dcmt_role'];
        $full_name = (string) $row['dcmt_full_name'];
        $role_label = dcmt_messaging_role_display_label($role);
        $out[] = [
            'id' => $uid,
            'full_name' => $full_name,
            'display_name' => dcmt_messaging_user_display_name($full_name, $role),
            'role' => $role,
            'role_label' => $role_label,
        ];
    }
    return $out;
}

function dcmt_messaging_get_conversation_header(PDO $pdo, int $conversation_id, int $user_id): ?array
{
    if (!dcmt_messaging_is_participant($pdo, $conversation_id, $user_id)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM dcmt_conversations WHERE dcmt_id = ?");
    $stmt->execute([$conversation_id]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conv) {
        return null;
    }
    $title = trim((string) ($conv['dcmt_title'] ?? ''));
    $full_name = '';
    $role_label = '';
    $display_name = $title;
    if (($conv['dcmt_type'] ?? '') === 'direct') {
        $s = $pdo->prepare("
            SELECT u.dcmt_full_name, u.dcmt_role FROM dcmt_users u
            INNER JOIN dcmt_conversation_participants p ON p.dcmt_user_id = u.dcmt_id
            WHERE p.dcmt_conversation_id = ? AND p.dcmt_user_id != ? LIMIT 1
        ");
        $s->execute([$conversation_id, $user_id]);
        $other = $s->fetch(PDO::FETCH_ASSOC);
        if ($other) {
            $full_name = trim((string) ($other['dcmt_full_name'] ?? ''));
            $role = (string) ($other['dcmt_role'] ?? '');
            $role_label = dcmt_messaging_role_display_label($role);
            $display_name = dcmt_messaging_user_display_name($full_name, $role);
            if ($full_name !== '') {
                $title = $full_name;
            }
        }
    }
    return [
        'id' => $conversation_id,
        'title' => $title ?: trans('messaging', 'conversation'),
        'full_name' => $full_name,
        'display_name' => $display_name,
        'role_label' => $role_label,
    ];
}

/**
 * Delete chat messages older than the retention period and repair stale references.
 *
 * @return array{deleted:int,conversations_updated:int,participants_updated:int,reply_refs_cleared:int}
 */
function dcmt_messaging_cleanup_old_messages(PDO $pdo, int $retentionDays = 7): array
{
    $empty = [
        'deleted' => 0,
        'conversations_updated' => 0,
        'participants_updated' => 0,
        'reply_refs_cleared' => 0,
    ];

    if ($retentionDays < 1) {
        return $empty;
    }

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'dcmt_messages'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        return $empty;
    }

    $cutoff = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))
        ->modify('-' . $retentionDays . ' days')
        ->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $deleteStmt = $pdo->prepare('DELETE FROM dcmt_messages WHERE dcmt_created_at < ?');
        $deleteStmt->execute([$cutoff]);
        $deleted = (int) $deleteStmt->rowCount();

        if ($deleted === 0) {
            $pdo->commit();
            return $empty;
        }

        $conversationsUpdated = (int) $pdo->exec("
            UPDATE dcmt_conversations c
            LEFT JOIN dcmt_messages lm ON lm.dcmt_id = c.dcmt_last_message_id
            LEFT JOIN (
                SELECT dcmt_conversation_id, MAX(dcmt_id) AS last_id, MAX(dcmt_created_at) AS last_at
                FROM dcmt_messages
                GROUP BY dcmt_conversation_id
            ) agg ON agg.dcmt_conversation_id = c.dcmt_id
            SET c.dcmt_last_message_id = agg.last_id,
                c.dcmt_last_message_at = agg.last_at
            WHERE c.dcmt_last_message_id IS NOT NULL AND lm.dcmt_id IS NULL
        ");

        $participantsUpdated = (int) $pdo->exec("
            UPDATE dcmt_conversation_participants p
            LEFT JOIN dcmt_messages rm ON rm.dcmt_id = p.dcmt_last_read_message_id
            SET p.dcmt_last_read_message_id = (
                SELECT MAX(m.dcmt_id) FROM dcmt_messages m
                WHERE m.dcmt_conversation_id = p.dcmt_conversation_id
                  AND m.dcmt_id <= p.dcmt_last_read_message_id
            )
            WHERE p.dcmt_last_read_message_id IS NOT NULL AND rm.dcmt_id IS NULL
        ");

        $replyRefsCleared = (int) $pdo->exec("
            UPDATE dcmt_messages m
            LEFT JOIN dcmt_messages reply ON reply.dcmt_id = m.dcmt_reply_to_message_id
            SET m.dcmt_reply_to_message_id = NULL
            WHERE m.dcmt_reply_to_message_id IS NOT NULL AND reply.dcmt_id IS NULL
        ");

        $pdo->commit();

        return [
            'deleted' => $deleted,
            'conversations_updated' => $conversationsUpdated,
            'participants_updated' => $participantsUpdated,
            'reply_refs_cleared' => $replyRefsCleared,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
