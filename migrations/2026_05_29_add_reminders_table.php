<?php
/**
 * Migration: create dcmt_reminders table
 *
 * Prefer: php migrations/run_schema_migrations.php
 * Legacy: php migrations/2026_05_29_add_reminders_table.php
 */

declare(strict_types=1);

require_once __DIR__ . '/migration_io.php';

dcmt_migration_require_cli();

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

if (!isset($dcmt_db) || !$dcmt_db instanceof Dcmt_Database) {
    dcmt_migration_write_err( "Migration failed: database bootstrap did not initialize Dcmt_Database.\n");
    exit(1);
}

try {
    $dcmt_db->addRemindersTable();
} catch (Throwable $e) {
    dcmt_migration_write_err( 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    dcmt_migration_write_err( "Migration failed: PDO not available.\n");
    exit(1);
}

$check = $dcmt_pdo->query("SHOW TABLES LIKE 'dcmt_reminders'");
if (!$check || $check->rowCount() === 0) {
    dcmt_migration_write_err( "Migration failed: dcmt_reminders table still missing.\n");
    exit(1);
}

dcmt_migration_write_out("OK: dcmt_reminders table is present.\n");
exit(0);
