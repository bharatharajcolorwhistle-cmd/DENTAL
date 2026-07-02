<?php
/**
 * CLI- and browser-safe migration output helpers.
 */

declare(strict_types=1);

if (!function_exists('dcmt_migration_is_cli')) {
    function dcmt_migration_is_cli(): bool
    {
        return PHP_SAPI === 'cli';
    }
}

if (!function_exists('dcmt_migration_prepare_output')) {
    function dcmt_migration_prepare_output(): void
    {
        if (!dcmt_migration_is_cli() && !headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
    }
}

if (!function_exists('dcmt_migration_write_err')) {
    function dcmt_migration_write_err(string $message): void
    {
        if (!dcmt_migration_is_cli() && !headers_sent()) {
            http_response_code(500);
        }

        if (dcmt_migration_is_cli() && defined('STDERR')) {
            fwrite(STDERR, $message);
            return;
        }

        if (dcmt_migration_is_cli()) {
            echo $message;
            return;
        }

        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dcmt_migration_write_out')) {
    function dcmt_migration_write_out(string $message): void
    {
        if (dcmt_migration_is_cli() && defined('STDOUT')) {
            fwrite(STDOUT, $message);
            return;
        }

        if (dcmt_migration_is_cli()) {
            echo $message;
            return;
        }

        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dcmt_migration_require_admin_web')) {
    /**
     * Browser-only: require an active admin session.
     * Database must already be bootstrapped in the caller's global scope.
     */
    function dcmt_migration_require_admin_web(): void
    {
        if (dcmt_migration_is_cli()) {
            return;
        }

        dcmt_migration_prepare_output();

        if (!function_exists('dcmt_validate_session')) {
            require_once dirname(__DIR__) . '/config/config.php';
        }

        if (!dcmt_validate_session()) {
            if (!headers_sent()) {
                http_response_code(401);
            }
            dcmt_migration_write_err(
                "Schema migration failed: login required. Sign in as admin first, then open this URL again.\n"
            );
            exit(1);
        }

        $user = dcmt_get_current_user();
        if (!$user || ($user['dcmt_status'] ?? '') !== 'active') {
            if (!headers_sent()) {
                http_response_code(403);
            }
            dcmt_migration_write_err("Schema migration failed: account is not active.\n");
            exit(1);
        }

        if (!function_exists('dcmt_is_admin') || !dcmt_is_admin()) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            dcmt_migration_write_err("Schema migration failed: admin access required.\n");
            exit(1);
        }
    }
}

if (!function_exists('dcmt_migration_require_access')) {
    function dcmt_migration_require_access(): void
    {
        dcmt_migration_require_admin_web();
    }
}

if (!function_exists('dcmt_migration_require_cli')) {
    function dcmt_migration_require_cli(): void
    {
        if (dcmt_migration_is_cli()) {
            return;
        }
        dcmt_migration_prepare_output();
        if (!headers_sent()) {
            http_response_code(403);
        }
        dcmt_migration_write_err(
            "This script must be run from the command line (CLI).\n"
            . "Example:\n  php migrations/run_schema_migrations.php\n"
        );
        exit(1);
    }
}
