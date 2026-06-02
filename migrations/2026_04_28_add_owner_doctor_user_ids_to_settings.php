<?php
/**
 * Migration: add owner_doctor_user_ids to dcmt_settings
 *
 * Inserts the clinic owner-doctors setting (JSON array of doctor user IDs) if missing.
 * Idempotent: safe to run multiple times.
 *
 * Run from project root (CLI):
 *   php migrations/2026_04_28_add_owner_doctor_user_ids_to_settings.php
 *
 * Windows (XAMPP), example:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\dev_dental\migrations\2026_04_28_add_owner_doctor_user_ids_to_settings.php
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
    $dcmt_db->ensureOwnerDoctorUserIdsSetting();
} catch (Throwable $e) {
    dcmt_migration_write_err( 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// Confirm row exists
if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    dcmt_migration_write_err( "Migration failed: PDO not available.\n");
    exit(1);
}

$stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'owner_doctor_user_ids' LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    dcmt_migration_write_err( "Migration failed: owner_doctor_user_ids row still missing after ensure.\n");
    exit(1);
}

dcmt_migration_write_out("OK: dcmt_settings.owner_doctor_user_ids is present (value: {$row['dcmt_setting_value']}).\n");
exit(0);
