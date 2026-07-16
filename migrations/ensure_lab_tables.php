<?php
/**
 * Force-fix lab work orders schema (drops legacy dcmt_lab_id, etc.).
 * Open once, then delete this file.
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
    }

    if ($force || ($exists && $count === 0)) {
        $dcmt_pdo->exec('DROP TABLE IF EXISTS dcmt_lab_work_orders');
        echo "Dropped dcmt_lab_work_orders\n";
    }

    dcmt_ensure_lab_tables($dcmt_pdo);

    $cols = $dcmt_pdo->query('SHOW COLUMNS FROM dcmt_lab_work_orders')->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns after: " . implode(', ', $cols) . "\n";
    echo "Has dcmt_lab_id: " . (in_array('dcmt_lab_id', $cols, true) ? 'YES (bad)' : 'no (good)') . "\n";
    echo "Schema OK: " . (dcmt_lab_work_orders_schema_ok($dcmt_pdo) ? 'yes' : 'no') . "\n";
    echo "DONE\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
