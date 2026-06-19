<?php
/**
 * MySQL connection hardening and production database security checks.
 */

if (!function_exists('dcmt_db_is_safe_identifier')) {
    /**
     * Validate a table or column name (letters, digits, underscore only).
     */
    function dcmt_db_is_safe_identifier(string $name): bool
    {
        return $name !== '' && (bool) preg_match('/^[a-zA-Z0-9_]+$/', $name);
    }
}

if (!function_exists('dcmt_db_is_remote_host')) {
    function dcmt_db_is_remote_host(string $host): bool
    {
        $host = strtolower(trim($host));
        return !in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}

if (!function_exists('dcmt_db_apply_connection_hardening')) {
    /**
     * Apply per-connection MySQL session settings after PDO connect.
     */
    function dcmt_db_apply_connection_hardening(PDO $pdo): void
    {
        try {
            $pdo->exec(
                "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
            );
        } catch (PDOException $e) {
            error_log('dcmt_db_apply_connection_hardening sql_mode: ' . $e->getMessage());
        }

        try {
            $pdo->exec('SET SESSION time_zone = \'-06:00\'');
        } catch (PDOException $e) {
            // Non-fatal; database.php may have already set this.
        }
    }
}

if (!function_exists('dcmt_db_validate_connection_params')) {
    /**
     * Reject malformed host/database names before they reach the DSN or SQL.
     */
    function dcmt_db_validate_connection_params(string $host, string $dbname): void
    {
        if ($host === '' || !preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) {
            throw new InvalidArgumentException('Invalid database host configuration.');
        }
        if ($dbname === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $dbname)) {
            throw new InvalidArgumentException('Invalid database name configuration.');
        }
    }
}

if (!function_exists('dcmt_db_validate_production_config')) {
    /**
     * Log warnings when production uses risky database credentials or transport.
     */
    function dcmt_db_validate_production_config(): void
    {
        if (!defined('DCMT_IS_PRODUCTION') || !DCMT_IS_PRODUCTION) {
            return;
        }

        $user = defined('DCMT_DB_USER') ? (string) DCMT_DB_USER : '';
        $pass = defined('DCMT_DB_PASS') ? (string) DCMT_DB_PASS : '';
        $host = defined('DCMT_DB_HOST') ? (string) DCMT_DB_HOST : '';

        if (strcasecmp($user, 'root') === 0) {
            error_log(
                'SECURITY WARNING: Production is using the MySQL root account. '
                . 'Create a least-privilege user (see sql/create_app_db_user.sql).'
            );
        }

        if ($pass === '') {
            error_log('SECURITY WARNING: Production database password is empty.');
        }

        $sslCa = trim((string) dcmt_env('DCMT_DB_SSL_CA', ''));
        $requireSsl = strtolower(trim((string) dcmt_env('DCMT_DB_REQUIRE_SSL', '1'))) !== '0';

        if (dcmt_db_is_remote_host($host)) {
            if ($sslCa === '' || !is_file($sslCa)) {
                $message = 'SECURITY WARNING: Remote MySQL host without a valid DCMT_DB_SSL_CA certificate.';
                if ($requireSsl) {
                    error_log($message . ' Set DCMT_DB_SSL_CA or DCMT_DB_REQUIRE_SSL=0 only if traffic is on a private network.');
                } else {
                    error_log($message);
                }
            }
        }
    }
}

if (!function_exists('dcmt_db_enforce_ssl_requirement')) {
    /**
     * Block remote production connections when TLS is required but not configured.
     */
    function dcmt_db_enforce_ssl_requirement(): void
    {
        if (!defined('DCMT_IS_PRODUCTION') || !DCMT_IS_PRODUCTION) {
            return;
        }

        $host = defined('DCMT_DB_HOST') ? (string) DCMT_DB_HOST : '';
        $requireSsl = strtolower(trim((string) dcmt_env('DCMT_DB_REQUIRE_SSL', '1'))) !== '0';
        if (!$requireSsl || !dcmt_db_is_remote_host($host)) {
            return;
        }

        $sslCa = trim((string) dcmt_env('DCMT_DB_SSL_CA', ''));
        if ($sslCa !== '' && is_file($sslCa)) {
            return;
        }

        error_log(
            'SECURITY: Database connection blocked — remote host requires TLS (DCMT_DB_REQUIRE_SSL) '
            . 'but DCMT_DB_SSL_CA is missing or invalid.'
        );

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Database SSL required but DCMT_DB_SSL_CA is not configured.\n");
            exit(1);
        }

        die('Database connection failed. Please contact the administrator.');
    }
}

if (!function_exists('dcmt_db_check_app_privileges')) {
    /**
     * Warn when the web app database user has elevated global privileges.
     */
    function dcmt_db_check_app_privileges(PDO $pdo): void
    {
        if (!defined('DCMT_IS_PRODUCTION') || !DCMT_IS_PRODUCTION) {
            return;
        }

        try {
            $stmt = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
            if (!$stmt) {
                return;
            }

            $grants = implode("\n", $stmt->fetchAll(PDO::FETCH_COLUMN));

            if (preg_match('/GRANT\s+ALL\s+PRIVILEGES/i', $grants)) {
                error_log(
                    'SECURITY WARNING: Database user has ALL PRIVILEGES. '
                    . 'Restrict to SELECT, INSERT, UPDATE, DELETE on the application schema only.'
                );
            }

            if (preg_match('/ON\s+\*.\*/i', $grants) && preg_match('/\b(SUPER|FILE|SHUTDOWN|CREATE|DROP|ALTER)\b/i', $grants)) {
                error_log(
                    'SECURITY WARNING: Database user has global administrative privileges on *.*.'
                );
            }
        } catch (PDOException $e) {
            error_log('dcmt_db_check_app_privileges: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dcmt_db_audit_retention_days')) {
    function dcmt_db_audit_retention_days(): int
    {
        $days = (int) dcmt_env('DCMT_AUDIT_RETENTION_DAYS', '365');
        return $days > 0 ? $days : 365;
    }
}

if (!function_exists('dcmt_db_prune_audit_log')) {
    /**
     * Remove audit log rows older than the configured retention period.
     */
    function dcmt_db_prune_audit_log(?PDO $pdo = null): int
    {
        global $dcmt_pdo;
        $pdo = $pdo ?? $dcmt_pdo ?? null;
        if (!($pdo instanceof PDO)) {
            return 0;
        }

        $days = dcmt_db_audit_retention_days();
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

        try {
            $stmt = $pdo->prepare('DELETE FROM dcmt_audit_log WHERE dcmt_created_at < ?');
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('dcmt_db_prune_audit_log: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('dcmt_db_prune_login_attempts')) {
    /**
     * Remove login attempt rows older than 30 days.
     */
    function dcmt_db_prune_login_attempts(?PDO $pdo = null, int $retentionDays = 30): int
    {
        global $dcmt_pdo;
        $pdo = $pdo ?? $dcmt_pdo ?? null;
        if (!($pdo instanceof PDO)) {
            return 0;
        }

        $retentionDays = max(1, $retentionDays);
        $cutoff = date('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        try {
            $stmt = $pdo->prepare('DELETE FROM dcmt_login_attempts WHERE dcmt_attempted_at < ?');
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('dcmt_db_prune_login_attempts: ' . $e->getMessage());
            return 0;
        }
    }
}
