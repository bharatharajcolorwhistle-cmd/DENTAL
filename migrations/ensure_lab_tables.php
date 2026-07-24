<?php
/**
 * Force-fix lab work orders schema (adds dcmt_specification, dcmt_file_number, drops legacy dcmt_lab_id, etc.).
 * Open once in the browser, then delete this file.
 *
 * Examples:
 *   /migrations/ensure_lab_tables.php
 *   /migrations/ensure_lab_tables.php?force=1   (rebuild empty/broken table)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/lab_functions.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    $count = 0;
    $exists = false;
    try {
        $exists = (bool) $dcmt_pdo->query("SHOW TABLES LIKE 'dcmt_lab_work_orders'")->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }

    if ($exists) {
        $count = (int) $dcmt_pdo->query('SELECT COUNT(*) FROM dcmt_lab_work_orders')->fetchColumn();
        echo "Rows before: {$count}\n";
        $cols = $dcmt_pdo->query('SHOW COLUMNS FROM dcmt_lab_work_orders')->fetchAll(PDO::FETCH_COLUMN);
        echo "Columns before: " . implode(', ', $cols) . "\n";
        echo "Has dcmt_specification before: " . (in_array('dcmt_specification', $cols, true) ? 'yes' : 'NO') . "\n";
        echo "Has dcmt_file_number before: " . (in_array('dcmt_file_number', $cols, true) ? 'yes' : 'NO') . "\n";
    }

    if ($force || ($exists && $count === 0)) {
        $dcmt_pdo->exec('DROP TABLE IF EXISTS dcmt_lab_work_orders');
        echo "Dropped dcmt_lab_work_orders\n";
    }

    dcmt_ensure_lab_tables($dcmt_pdo);

    // Explicitly ensure specification column exists on existing tables
    if (!dcmt_lab_table_has_column($dcmt_pdo, 'dcmt_lab_work_orders', 'dcmt_specification')) {
        $dcmt_pdo->exec('ALTER TABLE dcmt_lab_work_orders ADD COLUMN dcmt_specification TEXT NULL AFTER dcmt_color');
        echo "Added dcmt_specification column\n";
    } else {
        echo "dcmt_specification already present\n";
    }

    // Explicitly ensure file number column exists on existing tables
    if (!dcmt_lab_table_has_column($dcmt_pdo, 'dcmt_lab_work_orders', 'dcmt_file_number')) {
        $dcmt_pdo->exec('ALTER TABLE dcmt_lab_work_orders ADD COLUMN dcmt_file_number VARCHAR(100) NULL AFTER dcmt_box_number');
        echo "Added dcmt_file_number column\n";
    } else {
        echo "dcmt_file_number already present\n";
    }

    $cols = $dcmt_pdo->query('SHOW COLUMNS FROM dcmt_lab_work_orders')->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns after: " . implode(', ', $cols) . "\n";
    echo "Has dcmt_lab_id: " . (in_array('dcmt_lab_id', $cols, true) ? 'YES (bad)' : 'no (good)') . "\n";
    echo "Has dcmt_specification: " . (in_array('dcmt_specification', $cols, true) ? 'yes (good)' : 'NO (bad)') . "\n";
    echo "Has dcmt_file_number: " . (in_array('dcmt_file_number', $cols, true) ? 'yes (good)' : 'NO (bad)') . "\n";
    echo "Has dcmt_remote_doctor_id: " . (in_array('dcmt_remote_doctor_id', $cols, true) ? 'yes (good)' : 'NO (bad)') . "\n";
    echo "Has dcmt_verification_started_at: " . (in_array('dcmt_verification_started_at', $cols, true) ? 'yes (good)' : 'NO (bad)') . "\n";
    echo "Has dcmt_verification_ended_at: " . (in_array('dcmt_verification_ended_at', $cols, true) ? 'yes (good)' : 'NO (bad)') . "\n";
    echo "Has dcmt_verification_outcome: " . (in_array('dcmt_verification_outcome', $cols, true) ? 'yes (good)' : 'NO (bad)') . "\n";
    echo "Has dcmt_qr_token: " . (in_array('dcmt_qr_token', $cols, true) ? 'YES (bad)' : 'no (good)') . "\n";
    echo "Has dcmt_api_response: " . (in_array('dcmt_api_response', $cols, true) ? 'YES (bad)' : 'no (good)') . "\n";
    echo "Schema OK: " . (dcmt_lab_work_orders_schema_ok($dcmt_pdo) ? 'yes' : 'no') . "\n";
    echo "DONE\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
