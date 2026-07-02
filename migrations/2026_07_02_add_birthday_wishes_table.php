<?php
/**
 * Migration: create dcmt_birthday_wishes table
 *
 * Tracks birthday wishes acknowledged per patient/year so header alerts and
 * patient list candle icons hide after staff send a birthday message.
 *
 * Prefer (all migrations):
 *   {your_app_url}/migrations/run_schema_migrations.php
 *
 * Or this migration only:
 *   {your_app_url}/migrations/2026_07_02_add_birthday_wishes_table.php
 *
 * CLI (optional):
 *   php migrations/run_schema_migrations.php
 *   php migrations/2026_07_02_add_birthday_wishes_table.php
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
    $dcmt_db->addBirthdayWishesTable();
} catch (Throwable $e) {
    dcmt_migration_write_err('Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    dcmt_migration_write_err("Migration failed: PDO not available.\n");
    exit(1);
}

$check = $dcmt_pdo->query("SHOW TABLES LIKE 'dcmt_birthday_wishes'");
if (!$check || $check->rowCount() === 0) {
    dcmt_migration_write_err("Migration failed: dcmt_birthday_wishes table still missing.\n");
    exit(1);
}

$columns = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_birthday_wishes");
$required = ['dcmt_id', 'dcmt_patient_id', 'dcmt_wish_year', 'dcmt_sent_at', 'dcmt_sent_by_user_id'];
$present = [];
if ($columns) {
    while ($row = $columns->fetch(PDO::FETCH_ASSOC)) {
        $present[] = (string) ($row['Field'] ?? '');
    }
}
$missing = array_diff($required, $present);
if ($missing !== []) {
    dcmt_migration_write_err(
        'Migration failed: dcmt_birthday_wishes is missing columns: ' . implode(', ', $missing) . "\n"
    );
    exit(1);
}

dcmt_migration_write_out("OK: dcmt_birthday_wishes table is present.\n");
exit(0);
