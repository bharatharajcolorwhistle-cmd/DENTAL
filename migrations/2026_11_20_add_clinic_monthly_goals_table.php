<?php
/**
 * Migration: create dcmt_monthly_goals table
 *
 * Stores clinic-wide monthly goals that are not tied to a single user
 * (currently the product sales target). Rows are keyed by month + metric.
 *
 * Prefer (all migrations):
 *   {your_app_url}/migrations/run_schema_migrations.php
 *
 * Or this migration only:
 *   {your_app_url}/migrations/2026_11_20_add_clinic_monthly_goals_table.php
 *
 * CLI (optional):
 *   php migrations/run_schema_migrations.php
 *   php migrations/2026_11_20_add_clinic_monthly_goals_table.php
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
    $dcmt_db->addMonthlyGoalsTable();
} catch (Throwable $e) {
    dcmt_migration_write_err('Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    dcmt_migration_write_err("Migration failed: PDO not available.\n");
    exit(1);
}

$check = $dcmt_pdo->query("SHOW TABLES LIKE 'dcmt_monthly_goals'");
if (!$check || $check->rowCount() === 0) {
    dcmt_migration_write_err("Migration failed: dcmt_monthly_goals table still missing.\n");
    exit(1);
}

$columns = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_monthly_goals");
$required = ['dcmt_id', 'dcmt_goal_month', 'dcmt_goal_metric', 'dcmt_goal_amount', 'dcmt_created_by'];
$present = [];
if ($columns) {
    while ($row = $columns->fetch(PDO::FETCH_ASSOC)) {
        $present[] = (string) ($row['Field'] ?? '');
    }
}
$missing = array_diff($required, $present);
if ($missing !== []) {
    dcmt_migration_write_err(
        'Migration failed: dcmt_monthly_goals is missing columns: ' . implode(', ', $missing) . "\n"
    );
    exit(1);
}

dcmt_migration_write_out("OK: dcmt_monthly_goals table is present.\n");
exit(0);
