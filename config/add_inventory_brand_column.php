<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $stmt = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_inventory LIKE 'dcmt_brand'");
    $exists = $stmt && $stmt->rowCount() > 0;

    if ($exists) {
        echo "OK: dcmt_brand column already exists in dcmt_inventory.\n";
        exit;
    }

    $dcmt_pdo->exec("ALTER TABLE dcmt_inventory ADD COLUMN dcmt_brand VARCHAR(100) NULL AFTER dcmt_name");
    echo "OK: dcmt_brand column added to dcmt_inventory.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}

