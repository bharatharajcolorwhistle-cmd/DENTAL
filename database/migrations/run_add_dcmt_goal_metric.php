<?php
/**
 * One-time web migration: add dcmt_goal_metric to dcmt_doctor_goals
 *
 * Usage: log in as admin, then open in the browser, for example:
 *   {your_app}/database/migrations/run_add_dcmt_goal_metric.php
 *
 * Safe to run multiple times (no-op if the column already exists).
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

dcmt_require_admin();

header('Content-Type: text/plain; charset=utf-8');

try {
    $stmt = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_doctor_goals LIKE 'dcmt_goal_metric'");
    $exists = $stmt && $stmt->rowCount() > 0;

    if ($exists) {
        echo "OK: dcmt_goal_metric column already exists on dcmt_doctor_goals.\n";
        exit(0);
    }

    $dcmt_pdo->exec(
        "ALTER TABLE dcmt_doctor_goals ADD COLUMN dcmt_goal_metric ENUM('income','appointments') NOT NULL DEFAULT 'income' AFTER dcmt_goal_amount"
    );
    echo "OK: dcmt_goal_metric column added to dcmt_doctor_goals.\n";
    exit(0);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
