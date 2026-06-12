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
