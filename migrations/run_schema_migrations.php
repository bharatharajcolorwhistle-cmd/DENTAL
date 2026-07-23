<?php
/**
 * Apply all incremental schema migrations (columns, tables, indexes).
 *
 * Browser (log in as admin first):
 *   http://localhost/dev_dental/migrations/run_schema_migrations.php
 *   {your_app_url}/migrations/run_schema_migrations.php
 *
 * CLI (optional):
 *   php migrations/run_schema_migrations.php
 *
 * Latest upgrade (2026_07_21): odontogram treatments default doctor
 * (dcmt_doctor_user_id) for treatment-plan line prefills.
 */

declare(strict_types=1);

require_once __DIR__ . '/migration_io.php';

dcmt_migration_prepare_output();

define('DCMT_RUN_SCHEMA_MIGRATIONS', true);

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/schema_version.php';
require_once $root . '/config/database.php';

dcmt_migration_require_admin_web();

if (!isset($dcmt_db) || !$dcmt_db instanceof Dcmt_Database) {
    dcmt_migration_write_err("Schema migration failed: database not initialized.\n");
    exit(1);
}

if (!$dcmt_db->runSchemaMigrations()) {
    dcmt_migration_write_err("Schema migration failed. Check PHP error log for details.\n");
    exit(1);
}

$applied = defined('DCMT_SCHEMA_VERSION') ? DCMT_SCHEMA_VERSION : 'unknown';
$stored = $dcmt_db->getSchemaVersion();
dcmt_migration_write_out("OK: schema migrations completed. Version: {$stored} (target: {$applied}).\n");
exit(0);
