<?php
/**
 * Mandatory password change on first login.
 */

if (!function_exists('dcmt_user_must_change_password')) {
    function dcmt_user_must_change_password(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        return (bool) ($user && !empty($user['dcmt_must_change_password']));
    }
}

if (!function_exists('dcmt_enforce_password_change')) {
    /**
     * Redirect authenticated users who must change their password.
     */
    function dcmt_enforce_password_change(bool $json_response = false): void
    {
        if (!dcmt_is_logged_in() || !dcmt_user_must_change_password()) {
            return;
        }

        $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $allowed = [
            '/auth/change_password.php',
            '/auth/logout.php',
        ];
        foreach ($allowed as $prefix) {
            if (strpos($request_path, $prefix) !== false) {
                return;
            }
        }

        if ($json_response) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => trans('login', 'password_change_required'),
                'redirect' => DCMT_APP_URL . '/auth/change_password.php',
            ]);
            exit;
        }

        dcmt_show_message(trans('login', 'password_change_required'), 'warning');
        dcmt_redirect(DCMT_APP_URL . '/auth/change_password.php');
        exit;
    }
}

if (!function_exists('dcmt_refresh_session_user_flags')) {
    function dcmt_refresh_session_user_flags(PDO $pdo, int $user_id): void
    {
        if ($user_id <= 0 || !dcmt_is_logged_in()) {
            return;
        }
        try {
            $cols = 'dcmt_id, dcmt_username, dcmt_email, dcmt_full_name, dcmt_role, dcmt_status';
            if (dcmt_schema_has_column($pdo, 'dcmt_users', 'dcmt_must_change_password')) {
                $cols .= ', dcmt_must_change_password';
            }
            $stmt = $pdo->prepare("SELECT {$cols} FROM dcmt_users WHERE dcmt_id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && ($row['dcmt_status'] ?? '') === 'active') {
                $_SESSION['dcmt_user'] = $row;
            }
        } catch (PDOException $e) {
            error_log('dcmt_refresh_session_user_flags: ' . $e->getMessage());
        }
    }
}
