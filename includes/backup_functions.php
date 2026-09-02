<?php
/**
 * Daily backup header notifications (admin bell).
 */

if (!function_exists('dcmt_ensure_backup_notification_table')) {
    function dcmt_ensure_backup_notification_table(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dcmt_backup_notifications (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_user_id INT NOT NULL,
                dcmt_event VARCHAR(50) NOT NULL DEFAULT 'BACKUP_SUCCESS',
                dcmt_filename VARCHAR(255) NULL,
                dcmt_file_size_bytes BIGINT NULL,
                dcmt_dismissed TINYINT(1) NOT NULL DEFAULT 0,
                dcmt_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dcmt_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_backup_notif_user (dcmt_user_id, dcmt_dismissed, dcmt_created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");
    }
}

if (!function_exists('dcmt_backup_admin_user_ids')) {
    /**
     * Active built-in admin accounts plus owner doctors.
     *
     * @return list<int>
     */
    function dcmt_backup_admin_user_ids(PDO $pdo): array
    {
        $ids = [];
        $stmt = $pdo->query("
            SELECT dcmt_id
            FROM dcmt_users
            WHERE dcmt_role = 'admin'
              AND dcmt_status = 'active'
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if (!function_exists('dcmt_get_owner_doctor_user_ids')) {
            $owner_helper = __DIR__ . '/dcmt_owner_doctor.php';
            if (file_exists($owner_helper)) {
                require_once $owner_helper;
            }
        }
        if (function_exists('dcmt_get_owner_doctor_user_ids')) {
            foreach (dcmt_get_owner_doctor_user_ids() as $owner_id) {
                $owner_id = (int) $owner_id;
                if ($owner_id > 0) {
                    $ids[$owner_id] = $owner_id;
                }
            }
        }

        return array_values($ids);
    }
}

if (!function_exists('dcmt_backup_user_is_admin')) {
    /**
     * True for built-in admin or owner doctor.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_backup_user_is_admin(?array $user = null): bool
    {
        if ($user === null) {
            $user = function_exists('dcmt_get_current_user') ? dcmt_get_current_user() : null;
        }
        if (!$user) {
            return false;
        }
        if (($user['dcmt_role'] ?? '') === 'admin') {
            return true;
        }
        if (!function_exists('dcmt_is_owner_doctor_user')) {
            $owner_helper = __DIR__ . '/dcmt_owner_doctor.php';
            if (file_exists($owner_helper)) {
                require_once $owner_helper;
            }
        }
        return function_exists('dcmt_is_owner_doctor_user') && dcmt_is_owner_doctor_user($user);
    }
}

if (!function_exists('dcmt_backup_notify_admins')) {
    /**
     * Create a dismissible success notification for each active admin and owner doctor.
     */
    function dcmt_backup_notify_admins(PDO $pdo, string $filename, int $file_size_bytes): int
    {
        dcmt_ensure_backup_notification_table($pdo);
        $filename = trim($filename);
        if ($filename === '') {
            return 0;
        }

        $admin_ids = dcmt_backup_admin_user_ids($pdo);
        if ($admin_ids === []) {
            return 0;
        }

        $exists = $pdo->prepare("
            SELECT dcmt_id
            FROM dcmt_backup_notifications
            WHERE dcmt_user_id = ?
              AND dcmt_filename = ?
              AND dcmt_dismissed = 0
            LIMIT 1
        ");
        $insert = $pdo->prepare("
            INSERT INTO dcmt_backup_notifications (
                dcmt_user_id, dcmt_event, dcmt_filename, dcmt_file_size_bytes, dcmt_dismissed
            ) VALUES (?, 'BACKUP_SUCCESS', ?, ?, 0)
        ");

        $created = 0;
        foreach ($admin_ids as $admin_id) {
            $exists->execute([$admin_id, $filename]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $insert->execute([$admin_id, $filename, $file_size_bytes]);
            $created++;
        }
        return $created;
    }
}

if (!function_exists('dcmt_backup_fetch_active_notifications')) {
    function dcmt_backup_fetch_active_notifications(PDO $pdo, int $user_id, int $limit = 15): array
    {
        if ($user_id <= 0) {
            return [];
        }
        dcmt_ensure_backup_notification_table($pdo);
        $stmt = $pdo->prepare("
            SELECT *
            FROM dcmt_backup_notifications
            WHERE dcmt_user_id = ?
              AND dcmt_dismissed = 0
            ORDER BY dcmt_created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('dcmt_backup_count_active_notifications')) {
    function dcmt_backup_count_active_notifications(PDO $pdo, int $user_id): int
    {
        if ($user_id <= 0) {
            return 0;
        }
        dcmt_ensure_backup_notification_table($pdo);
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM dcmt_backup_notifications
            WHERE dcmt_user_id = ?
              AND dcmt_dismissed = 0
        ");
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('dcmt_backup_dismiss_notification')) {
    /**
     * @return array{success:bool,message?:string}
     */
    function dcmt_backup_dismiss_notification(PDO $pdo, int $notification_id, int $user_id): array
    {
        if ($notification_id <= 0 || $user_id <= 0) {
            return ['success' => false, 'message' => 'Invalid notification'];
        }
        dcmt_ensure_backup_notification_table($pdo);
        $stmt = $pdo->prepare("
            UPDATE dcmt_backup_notifications
            SET dcmt_dismissed = 1, dcmt_updated_at = NOW()
            WHERE dcmt_id = ?
              AND dcmt_user_id = ?
              AND dcmt_dismissed = 0
        ");
        $stmt->execute([$notification_id, $user_id]);
        if ($stmt->rowCount() < 1) {
            return ['success' => false, 'message' => 'Notification not found'];
        }
        return ['success' => true];
    }
}

if (!function_exists('dcmt_backup_format_size')) {
    function dcmt_backup_format_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return number_format($bytes / 1024 / 1024, 2) . ' MB';
    }
}
