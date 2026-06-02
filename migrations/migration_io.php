<?php
/**
 * CLI-safe output helpers (STDERR/STDOUT are undefined under Apache/CGI).
 */

declare(strict_types=1);

if (!function_exists('dcmt_migration_write_err')) {
    function dcmt_migration_write_err(string $message): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, $message);
            return;
        }
        echo $message;
    }
}

if (!function_exists('dcmt_migration_write_out')) {
    function dcmt_migration_write_out(string $message): void
    {
        if (defined('STDOUT')) {
            fwrite(STDOUT, $message);
            return;
        }
        echo $message;
    }
}

if (!function_exists('dcmt_migration_require_cli')) {
    function dcmt_migration_require_cli(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(403);
        }
        dcmt_migration_write_err(
            "This script must be run from the command line (CLI).\n"
            . "Example:\n  php migrations/run_schema_migrations.php\n"
        );
        exit(1);
    }
}
