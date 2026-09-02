<?php
/**
 * Role-based permission helpers.
 */

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
        return in_array($role, ['admin', 'doctor', 'staff', 'assistant'], true);
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
