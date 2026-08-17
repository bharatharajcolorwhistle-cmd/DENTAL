<?php
/**
 * Migration: clinic working hours lunch settings
 *
 * Inserts per-weekday lunch rows in dcmt_settings (lunch_start, lunch_end, lunch_active)
 * and any missing clinic open/close/active keys. Idempotent: INSERT IGNORE.
 *
 * Prefer (all migrations):
 *   {your_app_url}/migrations/run_schema_migrations.php
 *
 * Or this migration only:
 *   {your_app_url}/migrations/2026_08_17_add_clinic_working_hours_lunch_settings.php
 *
 * CLI (optional):
 *   php migrations/run_schema_migrations.php
 *   php migrations/2026_08_17_add_clinic_working_hours_lunch_settings.php
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
    $dcmt_db->ensureClinicWorkingHoursSettings();
} catch (Throwable $e) {
    dcmt_migration_write_err('Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    dcmt_migration_write_err("Migration failed: PDO not available.\n");
    exit(1);
}

$required_suffixes = ['start', 'end', 'active', 'lunch_start', 'lunch_end', 'lunch_active'];
$missing = [];
$chk = $dcmt_pdo->prepare("SELECT 1 FROM dcmt_settings WHERE dcmt_setting_key = ? LIMIT 1");
for ($day = 0; $day <= 6; $day++) {
    foreach ($required_suffixes as $suffix) {
        $key = "clinic_working_hours_{$day}_{$suffix}";
        $chk->execute([$key]);
        if (!$chk->fetchColumn()) {
            $missing[] = $key;
        }
    }
}

if ($missing !== []) {
    dcmt_migration_write_err(
        "Migration failed: still missing settings: " . implode(', ', $missing) . "\n"
    );
    exit(1);
}

dcmt_migration_write_out("OK: clinic working hours lunch settings are present for all weekdays.\n");
exit(0);
