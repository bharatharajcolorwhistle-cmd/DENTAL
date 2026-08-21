<?php
/**
 * Migration: comprehensive reminder recurrence options
 *
 * Adds yearly recurrence plus interval, weekdays, monthly mode, and end rules
 * (on date / after N occurrences / never). Idempotent via ensureReminderCalendarSchema().
 *
 * Prefer (all migrations):
 *   {your_app_url}/migrations/run_schema_migrations.php
 *
 * Or this migration only:
 *   {your_app_url}/migrations/2026_08_19_add_reminder_recurrence_options.php
 *
 * CLI (optional):
 *   php migrations/run_schema_migrations.php
 *   php migrations/2026_08_19_add_reminder_recurrence_options.php
 */

declare(strict_types=1);

require_once __DIR__ . '/migration_io.php';

dcmt_migration_prepare_output();

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

dcmt_migration_require_admin_web();

if (!isset($dcmt_db) || !$dcmt_db instanceof Dcmt_Database) {
    dcmt_migration_write_err("Migration failed: database bootstrap did not initialize Dcmt_Database.\n");
    exit(1);
}

try {
    $dcmt_db->ensureReminderCalendarSchema();
} catch (Throwable $e) {
    dcmt_migration_write_err('Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    dcmt_migration_write_err("Migration failed: PDO not available.\n");
    exit(1);
}

$required = [
    'dcmt_recurrence_type',
    'dcmt_recurrence_interval',
    'dcmt_recurrence_weekdays',
    'dcmt_recurrence_monthly_mode',
    'dcmt_recurrence_end_mode',
    'dcmt_recurrence_count',
    'dcmt_recurrence_end_date',
];
$missing = [];
foreach ($required as $col) {
    $chk = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_reminders LIKE " . $dcmt_pdo->quote($col));
    if (!$chk || $chk->rowCount() === 0) {
        $missing[] = $col;
    }
}

$typeCheck = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_reminders LIKE 'dcmt_recurrence_type'");
$typeRow = $typeCheck ? $typeCheck->fetch(PDO::FETCH_ASSOC) : null;
$typeDef = strtolower((string) ($typeRow['Type'] ?? ''));
if ($typeDef === '' || strpos($typeDef, 'yearly') === false) {
    $missing[] = 'dcmt_recurrence_type.yearly';
}

if ($missing !== []) {
    dcmt_migration_write_err(
        "Migration failed: still missing: " . implode(', ', $missing) . "\n"
    );
    exit(1);
}

dcmt_migration_write_out("OK: reminder recurrence options are present.\n");
exit(0);
