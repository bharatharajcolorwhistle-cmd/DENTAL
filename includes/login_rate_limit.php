<?php
/**
 * Login brute-force rate limiting.
 */

if (!function_exists('dcmt_login_rate_limit_config')) {
    function dcmt_login_rate_limit_config(): array
    {
        return [
            'max_attempts' => 5,
            'window_seconds' => 900,
        ];
    }
}

if (!function_exists('dcmt_login_rate_limit_ip')) {
    function dcmt_login_rate_limit_ip(): string
    {
        if (function_exists('dcmt_audit_client_ip')) {
            return dcmt_audit_client_ip();
        }
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }
}

if (!function_exists('dcmt_login_is_rate_limited')) {
    function dcmt_login_is_rate_limited(?PDO $pdo = null): bool
    {
        global $dcmt_pdo;
        $pdo = $pdo ?? $dcmt_pdo ?? null;
        if (!($pdo instanceof PDO)) {
            return false;
        }

        $ip = dcmt_login_rate_limit_ip();
        if ($ip === '') {
            return false;
        }

        $cfg = dcmt_login_rate_limit_config();
        $cutoff = date('Y-m-d H:i:s', time() - (int) $cfg['window_seconds']);

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM dcmt_login_attempts
                WHERE dcmt_ip = ? AND dcmt_attempted_at >= ?
            ");
            $stmt->execute([$ip, $cutoff]);
            return (int) $stmt->fetchColumn() >= (int) $cfg['max_attempts'];
        } catch (PDOException $e) {
            error_log('dcmt_login_is_rate_limited: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('dcmt_login_record_failed_attempt')) {
    function dcmt_login_record_failed_attempt(string $username, ?PDO $pdo = null): void
    {
        global $dcmt_pdo;
        $pdo = $pdo ?? $dcmt_pdo ?? null;
        if (!($pdo instanceof PDO)) {
            return;
        }

        $ip = dcmt_login_rate_limit_ip();
        if ($ip === '') {
            return;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO dcmt_login_attempts (dcmt_ip, dcmt_username, dcmt_attempted_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$ip, substr($username, 0, 100)]);
        } catch (PDOException $e) {
            error_log('dcmt_login_record_failed_attempt: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dcmt_login_clear_attempts')) {
    function dcmt_login_clear_attempts(?PDO $pdo = null): void
    {
        global $dcmt_pdo;
        $pdo = $pdo ?? $dcmt_pdo ?? null;
        if (!($pdo instanceof PDO)) {
            return;
        }

        $ip = dcmt_login_rate_limit_ip();
        if ($ip === '') {
            return;
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM dcmt_login_attempts WHERE dcmt_ip = ?');
            $stmt->execute([$ip]);
        } catch (PDOException $e) {
            error_log('dcmt_login_clear_attempts: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dcmt_login_prune_old_attempts')) {
    function dcmt_login_prune_old_attempts(?PDO $pdo = null): void
    {
        global $dcmt_pdo;
        $pdo = $pdo ?? $dcmt_pdo ?? null;
        if (!($pdo instanceof PDO)) {
            return;
        }

        $cfg = dcmt_login_rate_limit_config();
        $cutoff = date('Y-m-d H:i:s', time() - (int) $cfg['window_seconds']);

        try {
            $stmt = $pdo->prepare('DELETE FROM dcmt_login_attempts WHERE dcmt_attempted_at < ?');
            $stmt->execute([$cutoff]);
        } catch (PDOException $e) {
            error_log('dcmt_login_prune_old_attempts: ' . $e->getMessage());
        }
    }
}
