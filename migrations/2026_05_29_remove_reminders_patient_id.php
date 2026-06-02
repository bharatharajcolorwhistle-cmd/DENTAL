<?php
/**
 * Migration: remove dcmt_patient_id from dcmt_reminders
 *
 * Run from project root (CLI):
 *   php migrations/2026_05_29_remove_reminders_patient_id.php
 *
 * Or open in the browser (XAMPP):
 *   http://localhost/dev_dental/migrations/2026_05_29_remove_reminders_patient_id.php
 *
 * Note: loading any app page also applies this via config/database.php automatically.
 */

declare(strict_types=1);

require_once __DIR__ . '/migration_io.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

function dcmt_migration_message(string $message, bool $is_error = false): void
{
    if (PHP_SAPI === 'cli') {
        if ($is_error) {
            dcmt_migration_write_err($message . PHP_EOL);
        } else {
            dcmt_migration_write_out($message . PHP_EOL);
        }
        return;
    }

    if ($is_error && !headers_sent()) {
        http_response_code(500);
    }

    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "\n";
}

function dcmt_migration_exit(int $code): void
{
    exit($code);
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

if (!isset($dcmt_db) || !$dcmt_db instanceof Dcmt_Database) {
    dcmt_migration_message('Migration failed: database bootstrap did not initialize Dcmt_Database.', true);
    dcmt_migration_exit(1);
}

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    dcmt_migration_message('Migration failed: PDO not available.', true);
    dcmt_migration_exit(1);
}

try {
    $dcmt_db->addRemindersTable();
} catch (Throwable $e) {
    dcmt_migration_message('Migration failed: ' . $e->getMessage(), true);
    dcmt_migration_exit(1);
}

$check = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_reminders LIKE 'dcmt_patient_id'");
if ($check && $check->rowCount() > 0) {
    dcmt_migration_message('Migration failed: dcmt_patient_id column still present.', true);
    dcmt_migration_exit(1);
}

dcmt_migration_message('OK: dcmt_patient_id removed from dcmt_reminders.');
dcmt_migration_exit(0);
