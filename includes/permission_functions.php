<?php
/**
 * Role-based permission helpers.
 */

if (!function_exists('dcmt_role_label')) {
    /**
     * Human readable label for a role value.
     */
    function dcmt_role_label(string $role): string
    {
        $role = strtolower(trim($role));
        if ($role === '') {
            return '';
        }
        $label = trans('user', $role, '');
        if ($label !== '' && $label !== $role) {
            return $label;
        }
        return ucwords(str_replace('_', ' ', $role));
    }
}

if (!function_exists('dcmt_role_is_valid')) {
    /**
     * True when the role is one of the roles supported by the application.
     */
    function dcmt_role_is_valid($role): bool
    {
        return in_array(strtolower(trim((string) $role)), dcmt_roles(), true);
    }
}

if (!function_exists('dcmt_super_admin_exists')) {
    /**
     * True when at least one active Super Admin account exists.
     */
    function dcmt_super_admin_exists(): bool
    {
        global $dcmt_pdo;
        if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
            return false;
        }
        try {
            $stmt = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_role = 'super_admin' AND dcmt_status = 'active'");
            return $stmt && (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('dcmt_super_admin_ever_existed')) {
    /**
     * True when a Super Admin account has ever been created (any status).
     * Used for the one-time bootstrap only, so it cannot be reused later.
     */
    function dcmt_super_admin_ever_existed(): bool
    {
        global $dcmt_pdo;
        if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
            return false;
        }
        try {
            $stmt = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_role = 'super_admin'");
            return $stmt && (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('dcmt_can_assign_super_admin_role')) {
    /**
     * Only a Super Admin may grant the Super Admin role.
     *
     * Bootstrap exception: the very first Super Admin may be created by an
     * administrator, and only while no Super Admin account has ever existed.
     * Once one exists (even if later deactivated) administrators lose this
     * ability permanently, so only Super Admins can create further ones.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_can_assign_super_admin_role(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        if (!$user) {
            return false;
        }
        if (dcmt_role_is_super_admin($user['dcmt_role'] ?? '')) {
            return true;
        }
        if (($user['dcmt_role'] ?? '') !== 'admin') {
            return false;
        }
        return !dcmt_super_admin_ever_existed();
    }
}

if (!function_exists('dcmt_super_admin_count')) {
    /**
     * Number of active Super Admin accounts (used to protect the last one).
     */
    function dcmt_super_admin_count(): int
    {
        global $dcmt_pdo;
        if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
            return 0;
        }
        try {
            $stmt = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_role = 'super_admin' AND dcmt_status = 'active'");
            return $stmt ? (int) $stmt->fetchColumn() : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }
}

if (!function_exists('dcmt_user_role_supports_super_admin')) {
    /**
     * True when the database dcmt_users.dcmt_role enum accepts the Super Admin role.
     * Existing installations must run migrations/run_schema_migrations.php once.
     */
    function dcmt_user_role_supports_super_admin(): bool
    {
        global $dcmt_pdo;
        if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
            return false;
        }
        try {
            $col = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_role'")->fetch(PDO::FETCH_ASSOC);
            $type = (string) ($col['Type'] ?? '');
            return $type !== '' && stripos($type, 'super_admin') !== false;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('dcmt_is_staff_user')) {
    /**
     * True when the user has the staff role.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_is_staff_user(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        return (bool) ($user && (($user['dcmt_role'] ?? '') === 'staff'));
    }
}

if (!function_exists('dcmt_can_delete_records')) {
    /**
     * Staff may view and edit module records but cannot delete them.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_can_delete_records(?array $user = null): bool
    {
        return !dcmt_is_staff_user($user);
    }
}

if (!function_exists('dcmt_can_delete_appointments')) {
    /**
     * Permanently deleting an appointment (and its slot) is restricted to
     * Super Admins. Everyone else cancels instead, which keeps history.
     *
     * Add further roles here when special delete privileges are needed.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_can_delete_appointments(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        if (!$user) {
            return false;
        }
        return dcmt_is_super_admin($user);
    }
}

if (!function_exists('dcmt_can_access_inventory')) {
    /**
     * Inventory menu and pages: admin, owner doctor, staff, and assistant.
     * Limited (non-owner) doctors cannot access inventory.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_can_access_inventory(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        if (!$user) {
            return false;
        }

        $role = (string) ($user['dcmt_role'] ?? '');
        if (in_array($role, ['super_admin', 'admin', 'staff', 'assistant'], true)) {
            return true;
        }

        // Owner doctors have admin-like access to inventory.
        if ($role === 'doctor') {
            if (!function_exists('dcmt_get_owner_doctor_user_ids')) {
                $owner_helper = __DIR__ . '/dcmt_owner_doctor.php';
                if (file_exists($owner_helper)) {
                    require_once $owner_helper;
                }
            }
            if (function_exists('dcmt_get_owner_doctor_user_ids')) {
                $user_id = (int) ($user['dcmt_id'] ?? 0);
                return $user_id > 0 && in_array($user_id, dcmt_get_owner_doctor_user_ids(), true);
            }
        }

        return false;
    }
}

if (!function_exists('dcmt_can_access_lab')) {
    /**
     * Lab menu and pages: admin, doctor, staff, and assistant.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_can_access_lab(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        if (!$user) {
            return false;
        }
        $role = (string) ($user['dcmt_role'] ?? '');
        return in_array($role, ['super_admin', 'admin', 'doctor', 'staff', 'assistant'], true);
    }
}

if (!function_exists('dcmt_can_delete_lab')) {
    /**
     * Delete lab connections: built-in admin or owner doctor only.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_can_delete_lab(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        if (!$user) {
            return false;
        }
        if (dcmt_role_is_super_admin($user['dcmt_role'] ?? '') || ($user['dcmt_role'] ?? '') === 'admin') {
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

if (!function_exists('dcmt_lab_can_view_all_work_orders')) {
    /**
     * Admin, owner doctor, staff, and assistant can see every work order.
     * Regular doctors are limited to orders they created or are assigned to.
     *
     * @param array<string, mixed>|null $user
     */
    function dcmt_lab_can_view_all_work_orders(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        if (!$user) {
            return false;
        }
        if (dcmt_can_delete_lab($user)) {
            return true;
        }
        $role = (string) ($user['dcmt_role'] ?? '');
        return in_array($role, ['staff', 'assistant'], true);
    }
}

if (!function_exists('dcmt_lab_user_can_access_work_order')) {
    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed>|null $user
     */
    function dcmt_lab_user_can_access_work_order(array $order, ?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        if (!$user || !dcmt_can_access_lab($user)) {
            return false;
        }
        if (dcmt_lab_can_view_all_work_orders($user)) {
            return true;
        }
        $user_id = (int) ($user['dcmt_id'] ?? 0);
        $username = (string) ($user['dcmt_username'] ?? '');
        $order_doctor_user_id = (int) ($order['dcmt_doctor_user_id'] ?? 0);
        $order_created_by = (string) ($order['dcmt_created_by'] ?? '');
        return ($order_doctor_user_id > 0 && $order_doctor_user_id === $user_id)
            || ($order_created_by !== '' && $order_created_by === $username);
    }
}
