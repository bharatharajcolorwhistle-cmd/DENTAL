<?php
/**
 * Owner doctor list (clinic owners; multiple allowed). Used for admin-level access.
 */

if (!function_exists('dcmt_get_owner_doctor_user_ids')) {
    /**
     * Active doctor user IDs marked as clinic owners (from dcmt_settings JSON).
     *
     * @return list<int>
     */
    function dcmt_get_owner_doctor_user_ids(): array
    {
        global $dcmt_pdo, $dcmt_owner_doctor_ids_request_cache;
        if (isset($dcmt_owner_doctor_ids_request_cache) && is_array($dcmt_owner_doctor_ids_request_cache)) {
            return $dcmt_owner_doctor_ids_request_cache;
        }
        $dcmt_owner_doctor_ids_request_cache = [];
        if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
            return $dcmt_owner_doctor_ids_request_cache;
        }
        try {
            $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'owner_doctor_user_ids' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['dcmt_setting_value'] === '' || $row['dcmt_setting_value'] === null) {
                return $dcmt_owner_doctor_ids_request_cache;
            }
            $decoded = json_decode((string) $row['dcmt_setting_value'], true);
            if (!is_array($decoded)) {
                return $dcmt_owner_doctor_ids_request_cache;
            }
            $raw = [];
            foreach ($decoded as $id) {
                $i = (int) $id;
                if ($i > 0) {
                    $raw[] = $i;
                }
            }
            $raw = array_values(array_unique($raw));
            if ($raw === []) {
                return $dcmt_owner_doctor_ids_request_cache;
            }
            $placeholders = implode(',', array_fill(0, count($raw), '?'));
            $check = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_users WHERE dcmt_id IN ($placeholders) AND dcmt_role = 'doctor' AND dcmt_status = 'active'");
            $check->execute($raw);
            foreach ($check->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $dcmt_owner_doctor_ids_request_cache[] = (int) $id;
            }
        } catch (Throwable $e) {
            error_log('dcmt_get_owner_doctor_user_ids: ' . $e->getMessage());
        }
        return $dcmt_owner_doctor_ids_request_cache;
    }
}

if (!function_exists('dcmt_clear_owner_doctor_request_cache')) {
    function dcmt_clear_owner_doctor_request_cache(): void
    {
        global $dcmt_owner_doctor_ids_request_cache;
        unset($dcmt_owner_doctor_ids_request_cache);
    }
}

if (!function_exists('dcmt_is_builtin_admin')) {
    /**
     * True when the user has the built-in administrator role (not owner-doctor elevation).
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_is_builtin_admin(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        return $user && (($user['dcmt_role'] ?? '') === 'admin');
    }
}

if (!function_exists('dcmt_is_owner_doctor_user')) {
    /**
     * True when the user is an active doctor marked as a clinic owner.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_is_owner_doctor_user(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        if (!$user || ($user['dcmt_role'] ?? '') !== 'doctor') {
            return false;
        }
        $uid = (int) ($user['dcmt_id'] ?? 0);
        return $uid > 0 && in_array($uid, dcmt_get_owner_doctor_user_ids(), true);
    }
}

if (!function_exists('dcmt_can_delete_user')) {
    /**
     * Only built-in administrator accounts may delete users.
     *
     * @param array<string, mixed> $target_user
     */
    function dcmt_can_delete_user(array $target_user): bool
    {
        return dcmt_is_builtin_admin();
    }
}

if (!function_exists('dcmt_can_manage_owner_doctors')) {
    /**
     * True for built-in admin or any user already in the owner-doctor list.
     */
    function dcmt_can_manage_owner_doctors(): bool
    {
        $u = dcmt_get_current_user();
        if (!$u) {
            return false;
        }
        if (($u['dcmt_role'] ?? '') === 'admin') {
            return true;
        }
        if (($u['dcmt_role'] ?? '') !== 'doctor') {
            return false;
        }
        $uid = (int) ($u['dcmt_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }
        return in_array($uid, dcmt_get_owner_doctor_user_ids(), true);
    }
}

if (!function_exists('dcmt_save_owner_doctor_user_ids')) {
    /**
     * @param list<int> $ids
     */
    function dcmt_save_owner_doctor_user_ids(PDO $pdo, array $ids, string $username): bool
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, static fn ($id) => $id > 0);
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_users WHERE dcmt_id IN ($placeholders) AND dcmt_role = 'doctor' AND dcmt_status = 'active'");
            $stmt->execute(array_values($ids));
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        sort($ids);
        $json = json_encode(array_values($ids));
        $ins = $pdo->prepare("
            INSERT INTO dcmt_settings (dcmt_setting_key, dcmt_setting_name, dcmt_setting_value, dcmt_setting_type, dcmt_category, dcmt_created_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                dcmt_setting_value = VALUES(dcmt_setting_value),
                dcmt_updated_at = CURRENT_TIMESTAMP
        ");
        return $ins->execute([
            'owner_doctor_user_ids',
            'Owner Doctor Users',
            $json,
            'text',
            'Doctor',
            $username,
        ]);
    }
}
