<?php
/**
 * Structured PHI / security audit logging.
 */

if (!function_exists('dcmt_audit_client_ip')) {
    function dcmt_audit_client_ip(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $parts = explode(',', $raw);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '';
    }
}

if (!function_exists('dcmt_audit')) {
    /**
     * Record a security or PHI access event (no clinical text in details).
     */
    function dcmt_audit(string $action, string $resource_type = '', ?int $resource_id = null): void
    {
        global $dcmt_pdo;

        if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
            return;
        }

        $user = function_exists('dcmt_get_current_user') ? dcmt_get_current_user() : null;
        $user_id = is_array($user) ? (int) ($user['dcmt_id'] ?? 0) : 0;
        $username = is_array($user) ? (string) ($user['dcmt_username'] ?? '') : '';

        if ($action === 'login_failed' || $action === 'login_locked') {
            $user_id = 0;
            $username = '';
        }

        $ip = dcmt_audit_client_ip();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        try {
            $stmt = $dcmt_pdo->prepare("
                INSERT INTO dcmt_audit_log
                    (dcmt_user_id, dcmt_username, dcmt_action, dcmt_resource_type, dcmt_resource_id, dcmt_ip, dcmt_user_agent, dcmt_created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user_id > 0 ? $user_id : null,
                $username !== '' ? $username : null,
                $action,
                $resource_type !== '' ? $resource_type : null,
                $resource_id,
                $ip !== '' ? $ip : null,
                $ua !== '' ? $ua : null,
            ]);
        } catch (PDOException $e) {
            error_log('dcmt_audit failed: ' . $e->getMessage());
        }
    }
}
